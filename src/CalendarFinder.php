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

/**
     * Synchronizes the `shares` table with the real ACL grants stored on the
     * CalDAV server, for a calendar owned by the current user.
     *
     * Why: agendav never reads the server ACL; the `shares` table is its only
     * source for the "shares" tab, and on save it sends an ACL request that
     * REPLACES the whole calendar ACL. Without syncing, a grant set by another
     * application would be invisible in the UI... and wiped out on the next
     * save. This function does the opposite on every calendar-list load:
     *  - grant present on the server but missing from the table -> insert it
     *  - row in the table but no grant on the server anymore -> delete it
     *  - empty grant on the server (<grant/>) = no privilege left -> delete it
     *  - rw flag mismatch -> resync it
     */
    protected function syncSharesWithServer(Calendar $calendar)
    {
        try {
            // Only sync calendars OWNED by the current user: for a shared
            // (proxied) calendar the ACL is not readable (no read-acl privilege)
            // and the owner is not the current user.
            if ($calendar->getOwner() === null
                || $calendar->getOwner()->getUrl() !== $this->current_principal->getUrl()) {
                return;
            }

            // PROPFIND Depth 0 on the {DAV:}acl property of the calendar
            $body = '<?xml version="1.0" encoding="utf-8"?>'
                . '<propfind xmlns="DAV:"><prop><acl/></prop></propfind>';
            $response = $this->client->propfind($calendar->getUrl(), 0, $body);

            // The owner always has read-acl: if the acl property is missing from
            // the response, the ACL was emptied on the davical side (no grants
            // left) => treat the list as empty, not as an error.
            $acl = $response['{DAV:}acl'] ?? [];
            if (!is_array($acl)) {
                $acl = [];
            }

            // The sabre parser does not map {DAV:}acl to an object: it returns
            // the raw XML tree, i.e. an array of elements
            // ['name' => '{DAV:}ace', 'value' => [...], 'attributes' => ...]
            $server_grants = []; // principal URL => list of privileges (Clark notation)
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
                                $principal = $p['value']; // e.g. /caldav.php/ttest/
                            } elseif (in_array($p['name'] ?? '', ['{DAV:}all', '{DAV:}authenticated', '{DAV:}unauthenticated'], true)) {
                                $principal = $p['name']; // special principal
                            }
                            // {DAV:}property/{DAV:}owner = owner ACE: ignored
                        }
                    } elseif (($el['name'] ?? '') === '{DAV:}grant' && is_array($el['value'] ?? null)) {
                        foreach ($el['value'] as $priv) {
                            if (($priv['name'] ?? '') === '{DAV:}privilege') {
                                foreach ((array)($priv['value'] ?? []) as $pv) {
                                    $privs[] = $pv['name']; // e.g. {DAV:}read, {DAV:}write
                                }
                            }
                        }
                    }
                }
                if ($principal === null) {
                    continue; // owner ACE
                }
                if (in_array($principal, ['{DAV:}all', '{DAV:}authenticated', '{DAV:}unauthenticated'], true)) {
                    continue; // generic ACEs, not users
                }
                if ($principal === $this->current_principal->getUrl()) {
                    continue; // self
                }
                $server_grants[$principal] = array_merge($server_grants[$principal] ?? [], $privs);
            }

            // An ACE present but with an EMPTY <grant/> means it has no privilege
            // left (ACL emptied on the davical side): it is no longer an actual
            // share. Drop it so the corresponding shares row gets deleted.
            foreach ($server_grants as $p => $privs) {
                if (empty($privs)) {
                    unset($server_grants[$p]);
                }
            }

            // A grant is read-write if it contains write, write-content or all
            $is_rw = function (array $privs) {
                return in_array('{DAV:}all', $privs, true)
                    || in_array('{DAV:}write', $privs, true)
                    || in_array('{DAV:}write-content', $privs, true);
            };

            $db_shares = $this->shares_repository->getSharesOnCalendar($calendar);
            $db_by_with = [];
            foreach ($db_shares as $share) {
                $db_by_with[$share->getWith()] = $share;
            }

            // Cleanup: delete rows whose grant no longer exists on the server,
            // and resync the rw flag if the rights changed.
            foreach ($db_shares as $share) {
                $with = $share->getWith();
                if (!isset($server_grants[$with])) {
                    $this->shares_repository->remove($share);
                } elseif ($share->isWritable() !== $is_rw($server_grants[$with])) {
                    $share->setWritePermission($is_rw($server_grants[$with]));
                    $this->shares_repository->save($share);
                }
            }

            // Insert: add to the table grants set by other applications.
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
            // A network or parsing problem must never break the calendar list:
            // log it and leave the table as-is.
            error_log('syncSharesWithServer: ' . get_class($e) . ' ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}
