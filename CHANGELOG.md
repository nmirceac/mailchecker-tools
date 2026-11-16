# Changelog

## 0.0.1 (2026-11-17), pre-release

- Client for the Mail Checker API v2: sync, async and batch checks (references make retries safe), check lookups, webhook settings.
- `EmailCheck` model and `mailchecker_checks` table, kept up to date by signed `check.completed` webhooks, with the `afterCheckCompleted()` hook and the `EmailCheckCompleted` event.
- `DeliverableEmail` validation rule with a local cache.
- `mailchecker:setup`, `mailchecker:check`, `mailchecker:sync`.
- `Mailcheck::fake()` with assertions.
