# Changelog

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
