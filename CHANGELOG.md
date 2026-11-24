# Changelog

## 0.0.3 (unreleased), pre-release

- `Mailcheck::geoip($ip)`: location, ISP and ASN of an IPv4 or IPv6 address (`GET /api/v2/geoip/{ip}`, token ability `geoip:lookup`), with its own timeout (`MAILCHECKER_GEOIP_TIMEOUT`, 10 s) and a local cache for answers with a location (`MAILCHECKER_GEOIP_CACHE_MINUTES`, 1 day).
- `mailchecker:geoip {ip}` command.
- `Mailcheck::fake()->withGeoip([...])`, `assertGeoipLookedUp()`, `assertNoGeoipLookups()`.

## 0.0.2 (2026-11-17), pre-release

- Laravel 10: model casts declared as a property (`casts()` only exists from Laravel 11), so `details` is stored as JSON.

## 0.0.1 (2026-11-17), pre-release

- Client for the Mail Checker API v2: sync, async and batch checks (references make retries safe), check lookups, webhook settings.
- `EmailCheck` model and `mailchecker_checks` table, kept up to date by signed `check.completed` webhooks, with the `afterCheckCompleted()` hook and the `EmailCheckCompleted` event.
- `DeliverableEmail` validation rule with a local cache.
- `mailchecker:setup`, `mailchecker:check`, `mailchecker:sync`.
- `Mailcheck::fake()` with assertions.
