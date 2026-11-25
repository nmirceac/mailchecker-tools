<?php

namespace MailcheckerTools\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;

/**
 * Fails when the mailbox doesn't exist. Use it after Laravel's own `email` rule:
 *
 *     'email' => ['required', 'email', new DeliverableEmail],
 *     'email' => ['required', 'email', new DeliverableEmail(allowUnknown: false, failOpen: false)],
 *     'email' => ['required', 'email', new DeliverableEmail(allowDisposable: false)],
 *
 * Results are cached per address (mailchecker.rule.cache_minutes). "unknown" (greylisting,
 * timeouts, a domain that accepts every address) passes by default; if the platform can't be
 * reached the value passes too, unless failOpen is false. Throwaway (disposable) addresses pass
 * unless allowDisposable is false.
 */
class DeliverableEmail implements ValidationRule
{
    public function __construct(
        private ?bool $allowUnknown = null,
        private ?bool $failOpen = null,
        private ?int $cacheMinutes = null,
        private ?bool $allowDisposable = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be an email address.');

            return;
        }

        $email = Str::lower(trim($value));
        $allowUnknown = $this->allowUnknown ?? (bool) config('mailchecker.rule.allow_unknown', true);
        $failOpen = $this->failOpen ?? (bool) config('mailchecker.rule.fail_open', true);
        $minutes = $this->cacheMinutes ?? (int) config('mailchecker.rule.cache_minutes', 1440);
        $allowDisposable = $this->allowDisposable ?? (bool) config('mailchecker.rule.allow_disposable', true);

        try {
            ['result' => $result, 'disposable' => $disposable] = $this->result($email, $minutes);
        } catch (MailcheckApiException $e) {
            if ($e->status === 422) {
                $fail('The :attribute must be a valid email address.');

                return;
            }
            Log::warning('mailchecker-tools: could not check an address for validation', ['error' => $e->getMessage()]);
            if (! $failOpen) {
                $fail('The :attribute could not be verified right now. Please try again later.');
            }

            return;
        }

        if ($result === 'invalid') {
            $fail('The :attribute does not exist. Please check the address.');
        } elseif ($disposable && ! $allowDisposable) {
            $fail('The :attribute is a disposable address. Please use a permanent one.');
        } elseif ($result === 'unknown' && ! $allowUnknown) {
            $fail('The :attribute could not be verified.');
        }
    }

    /** @return array{result: string|null, disposable: bool} */
    private function result(string $email, int $minutes): array
    {
        $key = 'mailchecker-tools:rule:v2:'.sha1($email);
        $cached = $minutes > 0 ? Cache::get($key) : null;
        if (is_array($cached) && array_key_exists('result', $cached)) {
            return ['result' => is_string($cached['result']) ? $cached['result'] : null, 'disposable' => (bool) ($cached['disposable'] ?? false)];
        }

        $check = app(MailcheckClient::class)->check($email);
        $answer = [
            'result' => isset($check['result']) ? (string) $check['result'] : null,
            'disposable' => ($check['disposable'] ?? false) === true,
        ];

        // An unknown that is still being rechecked isn't cached: the next try may know more.
        if ($answer['result'] !== null && $minutes > 0 && ($answer['result'] !== 'unknown' || ($check['final'] ?? false))) {
            Cache::put($key, $answer, now()->addMinutes($minutes));
        }

        return $answer;
    }
}
