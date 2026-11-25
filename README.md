# mailchecker-tools

Laravel client for the **WeAnswer Mail Checker** (API v2): check whether email addresses exist,
synchronously, asynchronously or in batches, receive the results as signed webhooks, keep them in
your own table, and validate form input. Also look up the location of IP addresses and get currency
exchange rates.

* `Mailcheck::check()`: one typed client for the API (retries are safe: every check carries a reference)
* `EmailCheck` model + `mailchecker_checks` table, kept up to date by webhooks
* webhook receiver with signature verification, de-duplication, a hook and a Laravel event
* `new DeliverableEmail` validation rule, with a local cache
* `Mailcheck::geoip()`: country, city, ISP and ASN of an IPv4 or IPv6 address
* `Mailcheck::exchangeRate()` and `Mailcheck::convert()`: daily exchange rates (AUD, CAD, CHF, EUR, GBP, JPY, RON, SEK, USD, ZAR)
* `mailchecker:setup`, `mailchecker:check`, `mailchecker:sync`, `mailchecker:geoip` and `mailchecker:forex` commands
* `Mailcheck::fake()` for your tests

PHP 8.1+, Laravel 10, 11, 12 and 13.

## Installation

```bash
composer require nmirceac/mailchecker-tools
php artisan vendor:publish --tag=mailchecker-tools-models      # app/Models/EmailCheck.php
php artisan vendor:publish --tag=mailchecker-tools-migrations
php artisan migrate
```

Ask for an application and a token in the Mail Checker portal (**Applications → API**), with the
abilities `checks:run`, `checks:read` and `webhook:manage` (and `geoip:lookup` for GeoIP, `forex:read` for
exchange rates), then:

```dotenv
MAILCHECKER_API_URL=https://<mail checker host>/api/v2
MAILCHECKER_API_TOKEN=12|mc_…
```

```bash
php artisan mailchecker:setup --write-env
```

`setup` checks the token, registers this app's webhook URL (`/mailchecker/webhook`; pass `--url=` to
use another), writes `MAILCHECKER_WEBHOOK_SECRET` to `.env` and sends a test event. The webhook URL must
be reachable from the Mail Checker over HTTPS. Async checks and hooks run on your queue: run a worker.

Publish the config (`--tag=mailchecker-tools-config`) to change the table, model, queue, route or rule defaults.

## Checking addresses

```php
use App\Models\EmailCheck;

$check = EmailCheck::checkNow('someone@example.com');   // waits for the answer (up to ~45 s)
$check->isValid(); $check->isInvalid(); $check->isUnknown(); $check->isFinal();

EmailCheck::request('someone@example.com');             // async: queued, the webhook completes it
EmailCheck::requestMany($emails);                        // async, in batches of 100
```

| `state` | |
|---|---|
| `pending` | saved here, not yet accepted by the Mail Checker (the job is queued) |
| `queued` | accepted, not probed yet |
| `waiting` | probed, the result is `unknown` (e.g. greylisting) and is being rechecked for up to 30 minutes |
| `completed` | final when `final` is true |
| `failed` | refused (e.g. not an address) or the Mail Checker couldn't be reached |

| `result` | |
|---|---|
| `valid` | the mailbox exists |
| `invalid` | it doesn't, or the domain has no mail server |
| `unknown` | no definite answer (greylisting, timeouts, blocks, or a domain that accepts every address); usually safe to accept |

A sync answer can be `unknown` with `final = false`: the Mail Checker keeps rechecking, and a webhook
completes the row when it knows more.

Every answer also says what the address is (kept in `details`, read with these helpers):

```php
$check->isCatchAll();   // the domain accepts every address, so the mailbox can't be confirmed
                        // (result unknown, reason accept_all, final straight away)
$check->isDisposable(); // a throwaway provider (mailinator, yopmail, …)
$check->isRole();       // info@, support@, sales@, …: a function rather than a person
$check->isFree();       // a personal mailbox (Gmail, Yahoo, Outlook, consumer ISPs like MWEB), not a company domain
$check->didYouMean();   // 'jane@gmail.com' for jane@gamil.com, or null
```

### Using the API directly

```php
use MailcheckerTools\Facades\Mailcheck;

$check = Mailcheck::check('someone@example.com');                 // array: the API's check resource
$check = Mailcheck::check('someone@example.com', async: true, reference: $myUuid);
$rows = Mailcheck::checkBatch(['a@example.com', 'b@example.com']);  // split into calls of 100
$check = Mailcheck::getCheck($id);
$page = Mailcheck::checks(['result' => 'invalid', 'per_page' => 50]);
```

Errors throw `MailcheckerTools\Client\MailcheckApiException` (`status`, `errors`, `isRetryable()`).
Checks made this way are still recorded in your table when their webhook arrives.

## GeoIP lookups

```php
use MailcheckerTools\Facades\Mailcheck;

$geo = Mailcheck::geoip('81.196.40.181');   // or an IPv6 address

$geo['data']['success'];        // false: no location (always for private and reserved addresses)
$geo['data']['country_code'];   // "RO"
$geo['data']['city'];           // "Deva"
$geo['data']['asn'];            // "AS8708 - DIGI ROMANIA S.A."
$geo['cached'];                 // answered from the Mail Checker's cache
$geo['stale'];                  // geoiplookup.io was down: an older answer
$geo['fetched_at'];             // when it was looked up (null for private addresses)
```

`data` is [geoiplookup.io](https://geoiplookup.io)'s answer as the Mail Checker returns it: `ip`, `isp`, `org`,
`hostname`, `latitude`, `longitude`, `postal_code`, `city`, `country_code`, `country_name`, `continent_code`,
`continent_name`, `region`, `district`, `timezone_name`, `connection_type`, `asn_number`, `asn_org`, `asn`,
`currency_code`, `currency_name`, `language_code`, `language_name`, `success`, `premium`.

* The Mail Checker caches answers for 30 days. Answers with a location are also kept in your default cache
  for a day (`MAILCHECKER_GEOIP_CACHE_MINUTES`, 0 = off).
* An invalid address throws a `MailcheckApiException` with status 422. While geoiplookup.io is unavailable
  and nothing is cached it throws with status 503 (`isRetryable()` is true).
* Lookups count towards the application's per-minute limit. Timeout: `MAILCHECKER_GEOIP_TIMEOUT` (10 s).
* From the command line: `php artisan mailchecker:geoip 81.196.40.181`.

## Exchange rates

```php
use MailcheckerTools\Facades\Mailcheck;

$rate = Mailcheck::exchangeRate('ZAR', 'USD');                // today
$rate = Mailcheck::exchangeRate('ZAR', 'USD', '2025-06-30');  // or a past day (string or DateTimeInterface)

$rate['rate'];         // 0.0547…: how many USD one ZAR buys
$rate['date'];         // "2026-11-24": the UTC day of the rate
$rate['stale'];        // today's rates weren't available: an earlier day's (see date)
$rate['fetched_at'];   // when the Mail Checker got it from Fixer (null for the same currency)

$usd = Mailcheck::convert(1500, 'ZAR', 'USD');                // 1500 × the rate, not rounded
```

* Currencies: AUD, CAD, CHF, EUR, GBP, JPY, RON, SEK, USD, ZAR (any direction; the same currency is 1). The Mail Checker fetches
  every pair from Fixer once a day, shortly after 00:00 UTC, and keeps them; a past day it doesn't have yet
  is fetched on the first request.
* Answers that aren't stale are also kept in your default cache for an hour (`MAILCHECKER_FOREX_CACHE_MINUTES`,
  0 = off), so a "today" answer can be the previous day's for up to that long after midnight UTC.
* An unsupported currency or a bad date (future, before 1999, not `Y-m-d`) throws a `MailcheckApiException`
  with status 422. While Fixer is unavailable and nothing is stored it throws with status 503 (`isRetryable()`
  is true).
* Requests count towards the application's per-minute limit. Timeout: `MAILCHECKER_FOREX_TIMEOUT` (10 s).
* From the command line: `php artisan mailchecker:forex ZAR USD 1500 [--date=2025-06-30]`.

## Hooks and events

When a check gets its final result (by webhook, `checkNow()` or `mailchecker:sync`):

* `afterCheckCompleted()` on your model runs on the queue, if you define it;
* `MailcheckerTools\Events\EmailCheckCompleted` is fired.

```php
class EmailCheck extends \MailcheckerTools\Models\EmailCheck
{
    public function afterCheckCompleted(): void
    {
        if ($this->isInvalid()) { /* … */ }
    }
}
```

## Validation rule

```php
use MailcheckerTools\Rules\DeliverableEmail;

$request->validate([
    'email' => ['required', 'email', new DeliverableEmail],
    // stricter: unknown fails too, and so does an unreachable Mail Checker
    'work_email' => ['required', 'email', new DeliverableEmail(allowUnknown: false, failOpen: false)],
]);
```

Throwaway addresses pass by default; refuse them with `new DeliverableEmail(allowDisposable: false)` (or
`MAILCHECKER_RULE_ALLOW_DISPOSABLE=false`).

The rule makes a sync check and caches the result per address (`MAILCHECKER_RULE_CACHE_MINUTES`, 1 day).

## Webhooks

Events are POSTed as JSON to `/mailchecker/webhook`:

```json
{"id": "01J…", "type": "check.completed", "created_at": "…Z", "application": {"id": "…", "name": "…"},
 "data": {"id": "01J…", "email": "someone@example.com", "reference": "…", "state": "completed",
          "result": "valid", "reason": "accepted", "final": true, …}}
```

* `X-Mailcheck-Signature` = hex HMAC-SHA256 of the raw body with `MAILCHECKER_WEBHOOK_SECRET`, compared in
  constant time; `X-Mailcheck-Timestamp` must be within 5 minutes. Anything else gets a 401.
* Each event id (`X-Mailcheck-Webhook-Id`) is processed once (cache, 24 h); a failure lets the platform retry.
* Turn the route off with `MAILCHECKER_WEBHOOK_ROUTE=false`, or change its path with `MAILCHECKER_WEBHOOK_PATH`.

## Missed webhooks

If your app was down for a while, schedule the safety net:

```php
Schedule::command('mailchecker:sync')->everyFifteenMinutes();
```

It re-submits checks stuck in `pending` and refreshes unfinished ones from the API.

## Testing your app

```php
use MailcheckerTools\Facades\Mailcheck;

$fake = Mailcheck::fake(['gone@example.com' => 'invalid', '*' => 'valid']);

// … code under test …

$fake->assertChecked('gone@example.com');
$fake->assertNothingChecked();
$fake->assertBatchChecked(100);
```

No HTTP is made; `checkNow()` and the validation rule get the canned results. Give an array to set the
address flags too:

```php
Mailcheck::fake([
    'temp@mailinator.com' => ['result' => 'valid', 'disposable' => true],
    'jane@ymail.com' => ['catch_all' => true],          // unknown, reason accept_all
    'info@gamil.com' => ['result' => 'invalid', 'role' => true, 'did_you_mean' => 'info@gmail.com'],
]);
```

GeoIP answers default to a located address (Cape Town, ZA); override fields per address, or throw:

```php
$fake = Mailcheck::fake()->withGeoip([
    '81.196.40.181' => ['city' => 'Deva', 'country_code' => 'RO'],
    '10.0.0.1' => ['success' => false],
    '*' => new \MailcheckerTools\Client\MailcheckApiException('GeoIP lookup is temporarily unavailable.', 503),
]);

$fake->assertGeoipLookedUp('81.196.40.181');
$fake->assertNoGeoipLookups();
```

Exchange rates default to round numbers (one EUR buys 0.8 GBP, 5 RON, 1.25 USD, 20 ZAR, so USD/ZAR is 16);
set a pair (the other direction is worked out), or throw:

```php
$fake = Mailcheck::fake()->withExchangeRates([
    'USD/ZAR' => 18.2,
    'EUR/RON' => new \MailcheckerTools\Client\MailcheckApiException('Exchange rates are temporarily unavailable.', 503),
]);

$fake->assertExchangeRateRequested('USD/ZAR');
$fake->assertNoExchangeRateRequests();
```

## Development

```bash
composer install
composer test      # PHPUnit (SQLite; MAILCHECKER_TEST_DB=mysql + DB_* for MySQL, tables prefixed mct_)
composer lint      # Pint
composer analyse   # PHPStan level 6
```
