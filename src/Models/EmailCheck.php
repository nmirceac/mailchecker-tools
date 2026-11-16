<?php

namespace MailcheckerTools\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Enums\CheckResult;
use MailcheckerTools\Enums\CheckState;
use MailcheckerTools\Events\EmailCheckCompleted;
use MailcheckerTools\Jobs\RunHook;
use MailcheckerTools\Jobs\SubmitBatch;
use MailcheckerTools\Jobs\SubmitCheck;
use MailcheckerTools\MailcheckerTools;

/**
 * One address checked through the Mail Checker, kept up to date by its webhooks.
 *
 * @property int $id
 * @property string|null $check_id
 * @property string $reference
 * @property string $email
 * @property string|null $mode
 * @property string $state
 * @property string|null $result
 * @property string|null $reason
 * @property bool $final
 * @property bool $cached
 * @property string|null $mx_host
 * @property int|null $smtp_code
 * @property array<string, mixed>|null $details
 * @property Carbon|null $requested_at
 * @property Carbon|null $checked_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmailCheck extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['state' => 'pending', 'final' => false, 'cached' => false];

    /** A property, not casts(): the method only exists from Laravel 11. */
    protected $casts = [
        'final' => 'boolean',
        'cached' => 'boolean',
        'smtp_code' => 'integer',
        'details' => 'array',
        'requested_at' => 'datetime',
        'checked_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('mailchecker.checks.table', 'mailchecker_checks');
    }

    public function getConnectionName(): ?string
    {
        return config('mailchecker.checks.connection') ?: parent::getConnectionName();
    }

    /**
     * Save a check and submit it from the queue (async): the result arrives by webhook.
     *
     * @param  array<string, mixed>  $attributes  extra columns of your model
     */
    public static function request(string $email, array $attributes = []): static
    {
        $check = static::newCheck($email, $attributes);
        dispatch(MailcheckerTools::onQueue(new SubmitCheck(static::class, $check->getKey())));

        return $check;
    }

    /**
     * Save checks for many addresses and submit them as batches from the queue.
     *
     * @param  array<int, string>  $emails
     * @return list<static>
     */
    public static function requestMany(array $emails): array
    {
        $checks = array_map(fn (string $email) => static::newCheck($email), array_values($emails));
        foreach (array_chunk($checks, MailcheckClient::BATCH_MAX) as $chunk) {
            dispatch(MailcheckerTools::onQueue(new SubmitBatch(static::class, array_map(fn (self $c) => $c->getKey(), $chunk))));
        }

        return $checks;
    }

    /**
     * Check now and wait for the answer (up to ~45 s). If it comes back unknown and not final,
     * the webhook completes it later.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function checkNow(string $email, array $attributes = [], bool $force = false): static
    {
        $check = static::newCheck($email, $attributes);
        $check->applyApiCheck(app(MailcheckClient::class)->check($check->email, false, $check->reference, $force));

        return $check;
    }

    /** @param  array<string, mixed>  $attributes */
    protected static function newCheck(string $email, array $attributes = []): static
    {
        /** @var static $check */
        $check = static::query()->create([
            ...$attributes,
            'email' => Str::lower(trim($email)),
            'reference' => (string) Str::uuid(),
            'state' => CheckState::Pending->value,
        ]);

        return $check;
    }

    /**
     * Store the platform's view of this check (API response or webhook data). A final result
     * is never replaced by a non-final one. Returns whether anything changed; when the check
     * just became final, runs afterCheckCompleted() and fires EmailCheckCompleted.
     *
     * @param  array<string, mixed>  $data  the API's check resource
     */
    public function applyApiCheck(array $data): bool
    {
        if ($this->state === CheckState::Completed->value && $this->final && ! ($data['final'] ?? false)) {
            return false;
        }

        $wasFinal = $this->isFinal();
        $this->fill(array_filter([
            'check_id' => $data['id'] ?? null,
            'mode' => $data['mode'] ?? null,
            'state' => $data['state'] ?? null,
            'result' => $data['result'] ?? null,
            'reason' => $data['reason'] ?? null,
            'mx_host' => $data['mx_host'] ?? null,
            'smtp_code' => $data['smtp_code'] ?? null,
            'requested_at' => MailcheckerTools::localTime($data['requested_at'] ?? null),
            'checked_at' => MailcheckerTools::localTime($data['checked_at'] ?? null),
            'completed_at' => MailcheckerTools::localTime($data['completed_at'] ?? null),
        ], fn ($v) => $v !== null));
        $this->final = (bool) ($data['final'] ?? false);
        $this->cached = (bool) ($data['cached'] ?? false);
        $this->details = $data;

        if (! $this->isDirty(['state', 'result', 'final', 'check_id'])) {
            $this->save();

            return false;
        }

        $this->save();

        if (! $wasFinal && $this->isFinal()) {
            if (method_exists($this, 'afterCheckCompleted')) {
                dispatch(MailcheckerTools::onQueue(new RunHook(static::class, $this->getKey(), 'afterCheckCompleted')));
            }
            event(new EmailCheckCompleted($this));
        }

        return true;
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['state' => CheckState::Failed->value, 'details' => ['error' => $error] + (array) $this->details])->save();
    }

    public function stateEnum(): CheckState
    {
        return CheckState::tryFrom($this->state) ?? CheckState::Pending;
    }

    public function resultEnum(): ?CheckResult
    {
        return $this->result !== null ? CheckResult::tryFrom($this->result) : null;
    }

    /** The result won't change any more. */
    public function isFinal(): bool
    {
        return $this->state === CheckState::Completed->value && $this->final;
    }

    public function isValid(): bool
    {
        return $this->result === CheckResult::Valid->value;
    }

    public function isInvalid(): bool
    {
        return $this->result === CheckResult::Invalid->value;
    }

    public function isUnknown(): bool
    {
        return $this->result === CheckResult::Unknown->value;
    }
}
