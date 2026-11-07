<?php
namespace AgenDAV;

/*
 * Copyright (C) Jorge López Pérez <jorge@adobo.org>
 *
 *  This file is part of AgenDAV.
 *
 *  AgenDAV is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  any later version.
 *
 *  AgenDAV is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with AgenDAV.  If not, see <http://www.gnu.org/licenses/>.
 */

use AgenDAV\Repositories\SubscriptionsRepository;
use AgenDAV\Repositories\SharesRepository;
use AgenDAV\CalDAV\Client;
use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\Principal;
use AgenDAV\Data\Share;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * This class is used to find all accessible calendars for an user
 */
class CalendarFinder
{
    /** @var boolean */
    protected $sharing_enabled;

    /** @var \AgenDAV\CalDAV\Client */
    protected $client;

    /** @var \AgenDAV\Repositories\SharesRepository */
    protected $shares_repository;

    /** @var \AgenDAV\Repositories\SubscriptionsRepository */
    protected $subscriptions_repository;

    /** @var \Symfony\Component\HttpFoundation\Session\Session */
    protected $session;

    /** @var \AgenDAV\Data\Principal */
    protected $current_principal;

    /** @var array|null davical PostgreSQL config (read-only) */
    protected $davical_db = null;

    /** @var array per-request cache: calendar URL => (grantee URL => rw)|null */
    protected $direct_grants_cache = [];

    /**
    * @param \Symfony\Component\HttpFoundation\Session\Session $session
    * @param \AgenDAV\CalDAV\Client $client
    */
    public function __construct(Session $session, Client $client)
    {
        $this->sharing_enabled = false;
        $this->client = $client;
        $this->session = $session;
        $this->current_principal = new Principal($session->get('principal_url'));
    }

    /**
    * Sets the shares repository for this finder. Until it is called,
    * the finder disables all functionalities related to shared calendars
    *
    * @param \AgenDAV\Repositories\SharesRepository $shares_repository
    */
    public function setSharesRepository(SharesRepository $shares_repository)
    {
        $this->sharing_enabled = true;
        $this->shares_repository = $shares_repository;
    }

    /**
    * Sets the subscriptions repository for this finder.
    *
    * @param \AgenDAV\Repositories\SubscriptionsRepository $subscriptions_repository
    */
    public function setSubscriptionsRepository(SubscriptionsRepository $subscriptions_repository)
    {
        $this->subscriptions_repository = $subscriptions_repository;
    }

    /**
    * Returns all calendars for the current user
    *
    * @return \AgenDAV\CalDAV\Resource\Calendar[] Array of calendars
    */
    public function getCalendars()
    {
        $calendar_home_set = $this->session->get('calendar_home_set');

        $calendars = $this->client->getCalendars($calendar_home_set);
        foreach ($calendars as $calendar) {
            $calendar->setOwner($this->current_principal);
        }

        // Find additional proxied calendars
        $proxiedCalendars = $this->client->getProxiedCalendars($this->current_principal);
        $calendars = array_merge($calendars, $proxiedCalendars);

        if ($this->sharing_enabled) {
            // Add share info to own calendars
            $this->addShares($calendars);

            // Index already discovered calendars by normalized URL
            $by_url = [];
            foreach ($calendars as $calendar) {
                $by_url[rtrim($calendar->getUrl(), '/') . '/'] = $calendar;
            }

            // Also load calendars shared with current user
            $shared_calendars = $this->getCalendarSharedWith($this->current_principal);

            foreach ($shared_calendars as $shared) {
                $key = rtrim($shared->getUrl(), '/') . '/';
                if (isset($by_url[$key])) {
                    // Deja auto-decouvert (ex. via calendar-proxy-read) :
                    // on applique uniquement le displayname/color perso stockes
                    // dans la table shares, jamais le flag writable
                    // (le serveur est la source de verite pour les droits)
                    $existing = $by_url[$key];
                    $displayname = $shared->getProperty(Calendar::DISPLAYNAME);
                    if ($displayname !== null && $displayname !== '') {
                        $existing->setProperty(Calendar::DISPLAYNAME, $displayname);
                    }
                    $color = $shared->getProperty(Calendar::COLOR);
                    if ($color !== null && $color !== '') {
                        $existing->setProperty(Calendar::COLOR, $color);
                    }
                    $existing->setHidden($shared->isHidden());
                } else {
                    $calendars[] = $shared;
                    $by_url[$key] = $shared;
                }
            }
        }

        $subscribed_calendars = $this->getSubscribedCalendars($this->current_principal);
        $calendars = array_merge($calendars, $subscribed_calendars);

        return $calendars;
    }

    /**
    * Gets all calendars subscribed by current principal
    *
    * @param \AgenDAV\Data\Principal $principal Principal
    * @return \AgenDAV\CalDAV\Resource\Calendar[]
    */
    protected function getSubscribedCalendars(Principal $principal)
    {
        $result = [];

        $subscriptions = $this->subscriptions_repository->getSubscriptionsFor($principal);

        foreach ($subscriptions as $subscription) {
            $calendar_url = $subscription->getCalendar();
            $calendar = new Calendar($calendar_url);

            $calendar->setWritable(false);
            $calendar->setSubscribed(true);
            $owner_principal_url = $subscription->getOwner();
            $calendar->setOwner(new Principal($owner_principal_url));

            $subscription->applyCustomPropertiesTo($calendar);

            $result[] = $calendar;
        }

        return $result;
    }

    /**
    * Gets all calendars shared with current principal
    *
    * @param \AgenDAV\Data\Principal $principal Principal
    * @return \AgenDAV\CalDAV\Resource\Calendar[]
    */
    protected function getCalendarSharedWith(Principal $principal)
    {
        $result = [];

        $shares = $this->shares_repository->getSharesFor($principal);
        foreach ($shares as $share) {
            $calendar_url = $share->getCalendar();
            try {
                $calendar = $this->client->getCalendarByUrl($calendar_url);
                $share->applyCustomPropertiesTo($calendar);
                $calendar->setHidden((bool) $share->getProperty(Share::HIDDEN));
            } catch (\Exception $e) {
                // ACL was probably removed or modified. Ignore this calendar
                // TODO: some logging
                continue;
            }

            //$calendar->setWritable($share->isWritable());
            //$owner_principal_url = $share->getOwner();
            //$calendar->setOwner(new Principal($owner_principal_url));

            //$custom_properties = $share->getProperties();

            $owner_principal_url = $share->getOwner();
            $calendar->setOwner(new Principal($owner_principal_url));

            // Synchro : on recopie dans shares.rw les droits reels
            // remontes par le serveur (current-user-privilege-set)
            if ($calendar->isWritable() !== $share->isWritable()) {
                $share->setWritePermission($calendar->isWritable());
                $this->shares_repository->save($share);
            }

            $custom_properties = $share->getProperties();


            $this->applySharedProperties($calendar, $custom_properties);

            $result[] = $calendar;
        }

        return $result;
    }

    /**
    * Applies custom properties to a calendar
    *
    * @param \AgenDAV\CalDAV\Resource\Calendar $calendar
    * @param array $properties
    * @return void
    */
    protected function applySharedProperties(Calendar $calendar, array $properties)
    {
        // These are not real properties (with their XML namespace)
        foreach ($properties as $property => $value) {
            switch ($property) {
                case 'displayname':
                    $calendar->setProperty(Calendar::DISPLAYNAME, $value);
                    break;
                case 'color':
                    $calendar->setProperty(Calendar::COLOR, $value);
                    break;
                default:
                    // Ignore it
            }
        }
    }

    /**
    * Stores existing calendar shares inside each Calendar object
    *
    * @param \AgenDAV\CalDAV\Resource\Calendar[] $calendars
    */
    protected function addShares(array $calendars)
    {
        foreach ($calendars as $calendar) {
            $this->syncSharesWithServer($calendar);
            $shares = $this->shares_repository->getSharesOnCalendar($calendar);
            $calendar->setShares($shares);
        }
    }



    public function setDavicalDbConfig(array $config)
    {
        $this->davical_db = $config;
    }

    /**
     * DIRECT grants of a collection, read from davical's own grants table
     * (by_collection set = granted on the collection itself; principal-level
     * grants have by_collection NULL and are inheritance, not shares).
     * Returns grantee URL => rw bool, or null if DB access is unavailable.
     */
    protected function getDirectGrantsFromDavicalDb($calendar_url)
    {
        if (empty($this->davical_db)) {
            return null;
        }
        if (array_key_exists($calendar_url, $this->direct_grants_cache)) {
            return $this->direct_grants_cache[$calendar_url];
        }
        $result = [];
        try {
            $port = $this->davical_db['port'] ?? 5432;
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
                $this->davical_db['host'], $port, $this->davical_db['dbname']);
            $pdo = new \PDO($dsn, $this->davical_db['user'], $this->davical_db['password']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // davical stores dav_name WITHOUT the /caldav.php prefix
            $dav_name = preg_replace('#^/caldav\.php#', '', $calendar_url);
            $stmt = $pdo->prepare(
                'SELECT COALESCE(u.username, p.displayname) AS grantee, g.privileges::int AS privs
                 FROM grants g
                 JOIN collection c ON c.collection_id = g.by_collection
                 JOIN principal p ON p.principal_id = g.to_principal
                 LEFT JOIN usr u ON u.user_no = p.user_no
                 WHERE c.dav_name IN (:dav_name, :dav_name_full)'
            );
            $stmt->execute([':dav_name' => $dav_name, ':dav_name_full' => $calendar_url]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if ($row['grantee'] === null) {
                    continue;
                }
                // davical privilege bits: write-properties=2, write-content=4
                $rw = ((int)$row['privs'] & 6) !== 0;
                $result['/caldav.php/' . $row['grantee'] . '/'] = $rw;
            }
        } catch (\Throwable $e) {
            error_log('getDirectGrantsFromDavicalDb: ' . $e->getMessage());
            $result = null; // fallback to acl parsing
        }
        $this->direct_grants_cache[$calendar_url] = $result;
        return $result;
    }

    protected function syncSharesWithServer(Calendar $calendar)
    {
        try {
            // Only sync calendars OWNED by the current user.
            if ($calendar->getOwner() === null
                || $calendar->getOwner()->getUrl() !== $this->current_principal->getUrl()) {
                return;
            }

            $is_rw = function (array $privs) {
                return in_array('{DAV:}all', $privs, true)
                    || in_array('{DAV:}write', $privs, true)
                    || in_array('{DAV:}write-content', $privs, true);
            };

            // Preferred source: DIRECT grants from davical's grants table
            $direct = $this->getDirectGrantsFromDavicalDb($calendar->getUrl());

            if ($direct !== null) {
                $server_grants = [];
                foreach ($direct as $grantee_url => $rw) {
                    if ($grantee_url === $this->current_principal->getUrl()) {
                        continue; // self
                    }
                    $server_grants[$grantee_url] = $rw ? ['{DAV:}write'] : ['{DAV:}read'];
                }
            } else {
                // Fallback (no DB access): parse the acl property
                $body = '<?xml version="1.0" encoding="utf-8"?>'
                    . '<propfind xmlns="DAV:"><prop><acl/></prop></propfind>';
                $response = $this->client->propfind($calendar->getUrl(), 0, $body);
                $acl = $response['{DAV:}acl'] ?? [];
                if (!is_array($acl)) {
                    $acl = [];
                }
                $server_grants = [];
                foreach ($acl as $ace) {
                    if (($ace['name'] ?? '') !== '{DAV:}ace' || !is_array($ace['value'] ?? null)) {
                        continue;
                    }
                    $principal = null;
                    $privs = [];
                    foreach ($ace['value'] as $el) {
                        if (($el['name'] ?? '') === '{DAV:}principal') {
                            foreach ((array)($el['value'] ?? []) as $p) {
                                if (($p['name'] ?? '') === '{DAV:}href') {
                                    $principal = $p['value'];
                                } elseif (in_array($p['name'] ?? '', ['{DAV:}all', '{DAV:}authenticated', '{DAV:}unauthenticated'], true)) {
                                    $principal = $p['name'];
                                }
                            }
                        } elseif (($el['name'] ?? '') === '{DAV:}grant' && is_array($el['value'] ?? null)) {
                            foreach ($el['value'] as $priv) {
                                if (($priv['name'] ?? '') === '{DAV:}privilege') {
                                    foreach ((array)($priv['value'] ?? []) as $pv) {
                                        $privs[] = $pv['name'];
                                    }
                                }
                            }
                        }
                    }
                    if ($principal === null) {
                        continue;
                    }
                    if (in_array($principal, ['{DAV:}all', '{DAV:}authenticated', '{DAV:}unauthenticated'], true)) {
                        continue;
                    }
                    if ($principal === $this->current_principal->getUrl()) {
                        continue;
                    }
                    $server_grants[$principal] = array_merge($server_grants[$principal] ?? [], $privs);
                }
                foreach ($server_grants as $p => $privs) {
                    if (empty($privs)) {
                        unset($server_grants[$p]);
                    }
                }
            }

            $db_shares = $this->shares_repository->getSharesOnCalendar($calendar);
            $db_by_with = [];
            foreach ($db_shares as $share) {
                $db_by_with[$share->getWith()] = $share;
            }

            // Delete rows with no direct grant on the collection; resync rw
            foreach ($db_shares as $share) {
                $with = $share->getWith();
                if (!isset($server_grants[$with])) {
                    $this->shares_repository->remove($share);
                } elseif ($share->isWritable() !== $is_rw($server_grants[$with])) {
                    $share->setWritePermission($is_rw($server_grants[$with]));
                    $this->shares_repository->save($share);
                }
            }

            // Insert direct grants missing from the table
            foreach ($server_grants as $with => $privs) {
                if (isset($db_by_with[$with])) {
                    continue;
                }
                $share = new \AgenDAV\Data\Share();
                $share->setOwner($this->current_principal->getUrl());
                $share->setCalendar($calendar->getUrl());
                $share->setWith($with);
                $share->setWritePermission($is_rw($privs));
                $this->shares_repository->save($share);
            }
        } catch (\Throwable $e) {
            error_log('syncSharesWithServer: ' . get_class($e) . ' ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }


}
