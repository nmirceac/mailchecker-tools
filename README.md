# mailchecker-tools

Laravel client for the **WeAnswer Mail Checker** (API v2): check whether email addresses exist,
synchronously, asynchronously or in batches, receive the results as signed webhooks, keep them in
your own table, and validate form input.

* `Mailcheck::check()`: one typed client for the API (retries are safe: every check carries a reference)
* `EmailCheck` model + `mailchecker_checks` table, kept up to date by webhooks
* webhook receiver with signature verification, de-duplication, a hook and a Laravel event
* `new DeliverableEmail` validation rule, with a local cache
* `mailchecker:setup`, `mailchecker:check` and `mailchecker:sync` commands
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
abilities `checks:run`, `checks:read` and `webhook:manage`, then:

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
| `unknown` | no definite answer (greylisting, timeouts, blocks); usually safe to accept |

A sync answer can be `unknown` with `final = false`: the Mail Checker keeps rechecking, and a webhook
completes the row when it knows more.

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

No HTTP is made; `checkNow()` and the validation rule get the canned results.

## Development

```bash
composer install
composer test      # PHPUnit (SQLite; MAILCHECKER_TEST_DB=mysql + DB_* for MySQL, tables prefixed mct_)
composer lint      # Pint
composer analyse   # PHPStan level 6
```
