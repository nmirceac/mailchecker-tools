<?php

return [
    /*
     * The Mail Checker API. The token is an application token from the portal
     * (Applications → API), with the abilities checks:run, checks:read and webhook:manage
     * (and geoip:lookup for Mailcheck::geoip(), forex:read for Mailcheck::exchangeRate()).
     */
    'api' => [
        'url' => env('MAILCHECKER_API_URL'),
        'token' => env('MAILCHECKER_API_TOKEN'),
        // A sync check can take up to ~45 s on the platform.
        'timeout' => (int) env('MAILCHECKER_API_TIMEOUT', 60),
        // Retries on connection errors, 429 and 5xx. Every check carries a reference, so a
        // retry never checks an address twice.
        'retries' => (int) env('MAILCHECKER_API_RETRIES', 2),
    ],

    /*
     * Results of async and batch checks (and sync checks that were still unknown) arrive as
     * signed check.completed events. `php artisan mailchecker:setup --write-env` registers
     * the route below with the platform and writes the secret.
     */
    'webhook' => [
        'secret' => env('MAILCHECKER_WEBHOOK_SECRET'),
        'route' => [
            'enabled' => (bool) env('MAILCHECKER_WEBHOOK_ROUTE', true),
            'path' => env('MAILCHECKER_WEBHOOK_PATH', 'mailchecker/webhook'),
            'name' => 'mailchecker-tools.webhook',
            // Not the "web" group: no sessions or CSRF. No throttle: the platform retries.
            'middleware' => [],
        ],
        // Reject events whose X-Mailcheck-Timestamp is further than this from now (seconds).
        'tolerance' => (int) env('MAILCHECKER_WEBHOOK_TOLERANCE', 300),
        // Each event id is processed once within this window (default cache store).
        'dedupe_ttl' => (int) env('MAILCHECKER_WEBHOOK_DEDUPE_TTL', 86400),
    ],

    /*
     * Your model (published by `vendor:publish --tag=mailchecker-tools-models`); it must
     * extend MailcheckerTools\Models\EmailCheck.
     */
    'models' => [
        'check' => env('MAILCHECKER_CHECK_MODEL', 'App\\Models\\EmailCheck'),
    ],

    'checks' => [
        'connection' => env('MAILCHECKER_CHECKS_CONNECTION'),
        'table' => env('MAILCHECKER_CHECKS_TABLE', 'mailchecker_checks'),
    ],

    'queue' => [
        'connection' => env('MAILCHECKER_QUEUE_CONNECTION'),
        'name' => env('MAILCHECKER_QUEUE'),
    ],

    /*
     * Defaults for the DeliverableEmail validation rule.
     */
    'rule' => [
        // Reuse a result for the same address for this long (default cache store).
        'cache_minutes' => (int) env('MAILCHECKER_RULE_CACHE_MINUTES', 1440),
        // "unknown" (greylisting, timeouts) passes unless set to false.
        'allow_unknown' => (bool) env('MAILCHECKER_RULE_ALLOW_UNKNOWN', true),
        // If the platform can't be reached, let the value pass.
        'fail_open' => (bool) env('MAILCHECKER_RULE_FAIL_OPEN', true),
    ],

    /*
     * Mailcheck::geoip(): location of an IP address (the platform caches answers for 30 days).
     */
    'geoip' => [
        // Seconds; a lookup normally answers well under a second.
        'timeout' => (int) env('MAILCHECKER_GEOIP_TIMEOUT', 10),
        // Also keep answers with a location in your default cache store (0 = off).
        'cache_minutes' => (int) env('MAILCHECKER_GEOIP_CACHE_MINUTES', 1440),
    ],

    /*
     * Mailcheck::exchangeRate() and convert(): currency exchange rates (EUR, GBP, RON, USD, ZAR),
     * fetched by the platform once a day and kept there for good.
     */
    'forex' => [
        // Seconds; a stored rate answers well under a second, a day not stored yet takes one Fixer call.
        'timeout' => (int) env('MAILCHECKER_FOREX_TIMEOUT', 10),
        // Also keep rates in your default cache store (0 = off). Today's rate is fetched shortly after
        // 00:00 UTC, so a cached "today" answer can be the previous day's for up to this long after that.
        'cache_minutes' => (int) env('MAILCHECKER_FOREX_CACHE_MINUTES', 60),
    ],
];
