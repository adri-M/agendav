<?php
/**
 * AgenDAV instance configuration.
 *
 * Copy this file to settings.php and adjust the values for your setup.
 * Do not modify default.settings.php - it is overwritten on upgrades.
 *
 * Only settings that differ from the defaults need to be included here.
 * See default.settings.php for the full list with documentation.
 */

return [
    // ------------------------------------------------------------------
    // Site
    // ------------------------------------------------------------------

    'site.title' => 'My Calendar',

    // ------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------

    'db.options' => [
        'driver'   => 'pdo_mysql',
        'host'     => 'localhost',
        'dbname'   => 'agendav',
        'user'     => 'agendav',
        'password' => 'change-me',
        'charset'  => 'utf8mb4',
    ],


    // Giving access to davical database allow automatic synchronisation for sharing area in agendav.
    // This is a davical specific configuration: To display sharings, shares must already be present in agendav share table.
    // We can't use propfind only because of specific case: Principal have read, Collection have no access and default is disable collection --> Propfind will display the access to collection (it should not)
    'davical.db' => [
        'host' => 'localhost',
        'port' => 5432,
        'dbname' => 'davical',
        'user' => 'agendav_ro',
        'password' => 'change-me',
     ],

    // PostgreSQL example:
    // 'db.options' => [
    //     'driver'   => 'pdo_pgsql',
    //     'host'     => 'localhost',
    //     'dbname'   => 'agendav',
    //     'user'     => 'agendav',
    //     'password' => 'change-me',
    // ],

    // SQLite example (testing/single-user only):
    // 'db.options' => [
    //     'driver' => 'pdo_sqlite',
    //     'path'   => __DIR__ . '/../database/agendav.sqlite',
    // ],

    // ------------------------------------------------------------------
    // Security - REQUIRED: replace with a random string
    // Generate with: php -r 'echo bin2hex(random_bytes(32))."\n";'
    // ------------------------------------------------------------------

    'csrf.secret' => 'replace-with-a-random-64-hex-character-string',

    // ------------------------------------------------------------------
    // CalDAV server
    // ------------------------------------------------------------------

    // Internal URL used by AgenDAV to talk to the CalDAV server
    'caldav.baseurl' => 'http://localhost:5232/',

    // Authentication method: 'basic' or 'digest'
    'caldav.authmethod' => 'basic',

    // Whether to show CalDAV URLs to users
    'caldav.publicurls' => true,

    // Public URL shown to users (may differ from the internal one)
    'caldav.baseurl.public' => 'https://caldav.example.com/',

    // ------------------------------------------------------------------
    // Locale defaults (users can override these in their preferences)
    // ------------------------------------------------------------------

    'defaults.timezone'   => 'Europe/Berlin',
    'defaults.language'   => 'en',
    'defaults.time_format' => '24',
    'defaults.date_format' => 'dmy',
    'defaults.weekstart'  => 1,
];
