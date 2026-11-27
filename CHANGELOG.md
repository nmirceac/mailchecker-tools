# Changelog

## 0.0.6 (2026-11-27), pre-release

- **Servers by host, with failover.** `MAILCHECKER_HOSTS` lists the Mail Checker workers, separated by commas (`ck1.weanswer.it,ck2.weanswer.it`); the API is `https://<host>/api/v2` on each (a port, or `http://` for a local Mail Checker, can be given). **Not set or empty, the WeAnswer workers ck1 and ck2 are used**, so most apps need only `MAILCHECKER_API_TOKEN`. `MAILCHECKER_API_URL` is no longer read: remove it, or replace it with `MAILCHECKER_HOSTS` if it pointed elsewhere.
- A connection error or a server error (5xx) sends the call straight to the next worker; each worker is asked once per call (with two: the first, the second, then the call fails). A worker that couldn't be reached (or answered 502/503/504) is asked last for a minute. A 429 is retried on the same worker (up to `MAILCHECKER_API_RETRIES` times), other 4xx fail at once. Checks keep their reference across workers, so a retry never checks an address twice. GeoIP and exchange-rate calls fail over the same way. With one host, connection errors and 5xx are retried on it as before.
- Shorter timeouts: 1 s to connect (`MAILCHECKER_API_CONNECT_TIMEOUT`, new; DNS and TLS included) and 10 s for a whole request (`MAILCHECKER_API_TIMEOUT`, was 60 s). A check usually takes 3–4 s; one that takes longer than 10 s fails over like a worker that doesn't answer, and the platform still finishes and caches it.
- `MAILCHECKER_API_SPREAD` (default `true`): each call starts at a random worker, so they share the load; `false` tries the hosts in order (primary first).
- `mailchecker:setup` checks the token on each host and fails if one can't be reached.
- `MailcheckClient`: the constructor takes the hosts (a string, comma-separated, or a list); `urls()`, `forHost($host)`, `apiUrls($hosts)`, `DEFAULT_HOSTS`.

## 0.0.5 (2026-11-25), pre-release

- Check answers say what an address is: `catch_all` (the domain accepts every address; the result is then `unknown`, reason `accept_all`, final straight away), `role`, `free`, `disposable` and `did_you_mean`. They're kept in `details`; read them with `EmailCheck::isCatchAll()`, `isRole()`, `isFree()`, `isDisposable()` and `didYouMean()`. No migration needed.
- `DeliverableEmail(allowDisposable: false)` (or `MAILCHECKER_RULE_ALLOW_DISPOSABLE=false`) refuses throwaway addresses. The rule's cache keeps the flag (new cache keys, so earlier cached results are simply asked again).
- `Mailcheck::fake()` takes arrays with flags: `['temp@mailinator.com' => ['result' => 'valid', 'disposable' => true]]`.

## 0.0.4 (2026-11-25), pre-release

- Exchange rates for five more currencies: AUD, CAD, CHF, JPY and SEK (the platform now serves AUD, CAD, CHF, EUR, GBP, JPY, RON, SEK, USD and ZAR). No API change: `Mailcheck::exchangeRate()` and `convert()` already accepted any code the platform supports.
- `Mailcheck::fake()` knows the new currencies too (default rates follow the platform's fake ones: USD/JPY 160, USD/CHF 0.75, USD/SEK 10, USD/AUD 1.6, USD/CAD 1.28).

## 0.0.3 (2026-11-24), pre-release

- `Mailcheck::geoip($ip)`: location, ISP and ASN of an IPv4 or IPv6 address (`GET /api/v2/geoip/{ip}`, token ability `geoip:lookup`), with its own timeout (`MAILCHECKER_GEOIP_TIMEOUT`, 10 s) and a local cache for answers with a location (`MAILCHECKER_GEOIP_CACHE_MINUTES`, 1 day).
- `mailchecker:geoip {ip}` command.
- `Mailcheck::fake()->withGeoip([...])`, `assertGeoipLookedUp()`, `assertNoGeoipLookups()`.
- `Mailcheck::exchangeRate($from, $to, $date = null)` and `Mailcheck::convert($amount, $from, $to, $date = null)`: daily exchange rates for EUR, GBP, RON, USD and ZAR (`GET /api/v2/forex/{from}/{to}?date=`, token ability `forex:read`), with their own timeout (`MAILCHECKER_FOREX_TIMEOUT`, 10 s) and a local cache (`MAILCHECKER_FOREX_CACHE_MINUTES`, 1 hour).
- `mailchecker:forex {from} {to} {amount=1} {--date=}` command.
- `Mailcheck::fake()->withExchangeRates([...])`, `assertExchangeRateRequested()`, `assertNoExchangeRateRequests()`.

## 0.0.2 (2026-11-17), pre-release

- Laravel 10: model casts declared as a property (`casts()` only exists from Laravel 11), so `details` is stored as JSON.

## 0.0.1 (2026-11-17), pre-release

- Client for the Mail Checker API v2: sync, async and batch checks (references make retries safe), check lookups, webhook settings.
- `EmailCheck` model and `mailchecker_checks` table, kept up to date by signed `check.completed` webhooks, with the `afterCheckCompleted()` hook and the `EmailCheckCompleted` event.
- `DeliverableEmail` validation rule with a local cache.
- `mailchecker:setup`, `mailchecker:check`, `mailchecker:sync`.
- `Mailcheck::fake()` with assertions.
