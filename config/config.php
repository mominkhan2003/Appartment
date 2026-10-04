<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Application Configuration
 * ---------------------------------------------------------------------------
 * Edit the DB_* values below to match your MySQL/MariaDB server.
 * The defaults match a stock XAMPP / WAMP / MAMP installation.
 */

declare(strict_types=1);

return [

    /* ------------------------------------------------------------------ */
    /*  DATABASE  —  DEMO CONNECTION CREDENTIALS                              */
    /* ------------------------------------------------------------------ */
    'db' => [
        'host'     => 'premium281.web-hosting.com',
        'port'     => 3306,
        'database' => 'prosdfwo_greenbox',
        'username' => 'prosdfwo_greenbox',
        'password' => 'GreenBox@2026',            // XAMPP default is an empty root password
        'charset'  => 'utf8mb4',

        // PDO driver + options applied to every connection
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ],
    ],

    /* ------------------------------------------------------------------ */
    /*  APPLICATION                                                        */
    /* ------------------------------------------------------------------ */
    'app' => [
        'name'          => 'FlatMate',
        'tagline'       => 'Apartment / Shared Flat Management',
        'version'       => '1.0.0',
        'env'           => 'local',            // local | production
        'timezone'      => 'Asia/Dhaka',

        // URL prefix of the folder containing index.php.
        // Leave EMPTY ('') to auto-detect, which works at /flatmate/,
        // /Appartment/, or the vhost root. Set an explicit value only if the
        // app is reached through a rewrite that hides the real path.
        'base_url'      => '',

        'currency'      => '€',               // EUR
        'currency_code' => 'EUR',

        // Session tuning
        'session_name'  => 'FLATMATE_SESSID',
        'session_life'  => 60 * 60 * 24 * 14,   // 14 days "remember me"
        'idle_timeout'  => 60 * 30,            // 30 min idle

        // Pagination
        'per_page'      => 20,

        // Duty scheduler generation horizon (days forward / back)
        'chore_horizon_forward' => 21,
        'chore_horizon_back'    => 14,
    ],

    /* ------------------------------------------------------------------ */
    /*  FEATURE FLAGS                                                      */
    /* ------------------------------------------------------------------ */
    'features' => [
        'auto_generate_chores'   => true,
        'strict_offboarding'     => true,   // block offboarding on non-zero balance
        'min_debtors_for_optimal'=> 10,     // above this, fall back to greedy settle
        'allow_duplicate_split'  => false,
    ],
];
