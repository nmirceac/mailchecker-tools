<?php

return [
    /*
     * The Mail Checker API. The token is an application token from the portal
     * (Applications → API), with the abilities checks:run, checks:read and webhook:manage
     * (and geoip:lookup for Mailcheck::geoip(), forex:read for Mailcheck::exchangeRate()).
     */
    'api' => [
        // The Mail Checker workers, separated by commas: ck1.weanswer.it,ck2.weanswer.it (the default when
        // not set or empty, MailcheckClient::DEFAULT_HOSTS). The API is https://<host>/api/v2; a port or
        // another scheme can be given (http://127.0.0.1:8001). A call that can't reach a worker, or gets a
        // server error from it, goes to the next; each is asked once per call. A worker that can't be
        // reached is tried last for a minute.
        'hosts' => env('MAILCHECKER_HOSTS'),
        // With several hosts: true starts each call at a random one, so the workers share the load;
        // false always tries them in the order given (the first is the primary, the others are backups).
        'spread' => (bool) env('MAILCHECKER_API_SPREAD', true),
        'token' => env('MAILCHECKER_API_TOKEN'),
        // Seconds for a whole request, connecting included. A sync check usually takes 3–4 s (none of the
        // first few hundred took over 7 s); the platform allows itself up to 45 s for slow mail servers, and
        // if a check takes longer than this the call fails (the platform still finishes it, so the next check
        // of the address answers from its cache).
        'timeout' => (int) env('MAILCHECKER_API_TIMEOUT', 10),
        // Seconds to connect (DNS, TCP and TLS). A worker that can't be reached in time: the call goes to the next.
        'connect_timeout' => (int) env('MAILCHECKER_API_CONNECT_TIMEOUT', 1),
        // Retries on 429, and on connection errors and 5xx when there is only one host (with several, the
        // next worker is asked instead). Every check carries a reference, so a retry, on the same worker or
        // another, never checks an address twice.
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
        // "unknown" (greylisting, timeouts, a domain that accepts every address) passes unless set to false.
        'allow_unknown' => (bool) env('MAILCHECKER_RULE_ALLOW_UNKNOWN', true),
        // Throwaway (disposable) addresses pass unless set to false.
        'allow_disposable' => (bool) env('MAILCHECKER_RULE_ALLOW_DISPOSABLE', true),
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
     * Mailcheck::exchangeRate() and convert(): currency exchange rates (AUD, CAD, CHF, EUR, GBP, JPY, RON, SEK, USD, ZAR),
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
