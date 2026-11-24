<?php

namespace MailcheckerTools\Testing;

use Closure;
use Illuminate\Support\Str;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Mailcheck::fake(): answers every call locally and records it. Results are given per address
 * ('*' for the rest; default "valid"): valid, invalid, or unknown (final).
 */
class FakeMailcheckClient extends MailcheckClient
{
    /** @var array<string, array<string, mixed>> checks by id */
    public array $checks = [];

    /** @var list<int> sizes of the batch calls */
    public array $batches = [];

    /** @var array{url: string|null, secret: string, events: list<string>} */
    public array $webhookSettings = ['url' => null, 'secret' => 'whsec_fake', 'events' => ['check.completed', 'webhook.test']];

    /** @var array<string, array<string, mixed>|Throwable> GeoIP answers by address ('*' for the rest) */
    public array $geoipAnswers = [];

    /** @var list<string> addresses looked up, in order */
    public array $geoipLookups = [];

    /** @param  array<string, string>  $results */
    public function __construct(public array $results = [])
    {
        parent::__construct('https://mailchecker.fake/api/v2', 'fake-token');
    }

    public function check(string $email, bool $async = false, ?string $reference = null, bool $force = false, ?int $maxAgeHours = null): array
    {
        $email = Str::lower(trim($email));
        foreach ($this->checks as $existing) {
            if ($reference !== null && $existing['reference'] === $reference) {
                return $existing + ['created' => false];
            }
        }

        $check = $this->make($email, $reference, $async ? 'async' : 'sync', $async);
        $this->checks[$check['id']] = $check;

        return $check + ['created' => true];
    }

    public function checkBatch(array $items, bool $force = false): array
    {
        $this->batches[] = count($items);
        $batchId = (string) Str::ulid();

        return array_map(function ($item, int $index) use ($batchId) {
            $email = Str::lower(trim(is_array($item) ? (string) $item['email'] : (string) $item));
            $check = $this->make($email, is_array($item) ? ($item['reference'] ?? null) : null, 'batch', true) + ['batch_id' => $batchId];
            $this->checks[$check['id']] = $check;

            return ['index' => $index, 'created' => true, 'check' => $check, 'errors' => null, 'batch_id' => $batchId];
        }, $items, array_keys($items));
    }

    public function getCheck(string $id): array
    {
        return $this->checks[$id] ?? throw new MailcheckApiException('Not found.', 404);
    }

    public function checks(array $filters = []): array
    {
        return ['data' => array_values($this->checks), 'next_cursor' => null];
    }

    public function webhook(): array
    {
        return $this->webhookSettings;
    }

    public function updateWebhook(?string $url, bool $rotateSecret = false): array
    {
        $this->webhookSettings['url'] = $url;
        if ($rotateSecret) {
            $this->webhookSettings['secret'] = 'whsec_'.Str::random(40);
        }

        return $this->webhookSettings;
    }

    public function testWebhook(): array
    {
        return ['ok' => true, 'status' => 200, 'error' => null];
    }

    /**
     * Canned GeoIP answers by address ('*' for the rest): fields merged over a default answer
     * (Cape Town, ZA), or an exception to throw.
     *
     *     Mailcheck::fake()->withGeoip(['10.0.0.1' => ['success' => false], '*' => ['country_code' => 'RO']]);
     *
     * @param  array<string, array<string, mixed>|Throwable>  $answers
     */
    public function withGeoip(array $answers): static
    {
        foreach ($answers as $ip => $answer) {
            $this->geoipAnswers[strtolower(trim((string) $ip))] = $answer;
        }

        return $this;
    }

    public function geoip(string $ip): array
    {
        $ip = strtolower(trim($ip));
        $this->geoipLookups[] = $ip;

        $answer = $this->geoipAnswers[$ip] ?? $this->geoipAnswers['*'] ?? [];
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return ['data' => self::geoipAnswer($ip, $answer), 'cached' => false, 'stale' => false, 'fetched_at' => now()->utc()->toIso8601ZuluString()];
    }

    /**
     * A GeoIP answer in the platform's shape (a located address unless overridden).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function geoipAnswer(string $ip, array $overrides = []): array
    {
        return array_merge([
            'ip' => $ip, 'isp' => 'Fake Telecom', 'org' => 'Fake Telecom', 'hostname' => '',
            'latitude' => -33.9249, 'longitude' => 18.4241, 'postal_code' => '', 'city' => 'Cape Town',
            'country_code' => 'ZA', 'country_name' => 'South Africa', 'continent_code' => 'AF', 'continent_name' => 'Africa',
            'region' => 'Western Cape', 'district' => '', 'timezone_name' => 'Africa/Johannesburg', 'connection_type' => 'Corporate',
            'asn_number' => 64500, 'asn_org' => 'Fake Telecom', 'asn' => 'AS64500 - Fake Telecom',
            'currency_code' => 'ZAR', 'currency_name' => 'South African Rand', 'language_code' => 'en', 'language_name' => 'English',
            'success' => true, 'premium' => false,
        ], $overrides);
    }

    /** Assert an address was looked up ($times times), or that a lookup matches the callback. */
    public function assertGeoipLookedUp(string|Closure|null $ip = null, int $times = 1): void
    {
        $matches = array_filter($this->geoipLookups, fn (string $looked) => match (true) {
            $ip === null => true,
            $ip instanceof Closure => (bool) $ip($looked),
            default => $looked === strtolower(trim($ip)),
        });

        Assert::assertCount($times, $matches, 'Unexpected number of GeoIP lookups'.(is_string($ip) ? " for {$ip}" : '').'.');
    }

    public function assertNoGeoipLookups(): void
    {
        Assert::assertSame([], $this->geoipLookups, 'IP addresses were looked up unexpectedly.');
    }

    /** Assert an address was checked ($times times), or that a check matches the callback. */
    public function assertChecked(string|Closure|null $email = null, int $times = 1): void
    {
        $matches = array_filter($this->checks, fn (array $c) => match (true) {
            $email === null => true,
            $email instanceof Closure => (bool) $email($c),
            default => $c['email'] === Str::lower(trim($email)),
        });

        Assert::assertCount($times, $matches, 'Unexpected number of checks'.(is_string($email) ? " for {$email}" : '').'.');
    }

    public function assertNothingChecked(): void
    {
        Assert::assertSame([], $this->checks, 'Addresses were checked unexpectedly.');
    }

    public function assertBatchChecked(?int $size = null): void
    {
        Assert::assertNotEmpty($this->batches, 'No batch was checked.');
        if ($size !== null) {
            Assert::assertContains($size, $this->batches, "No batch of {$size} address(es) was checked.");
        }
    }

    /** @return array<string, mixed> */
    private function make(string $email, ?string $reference, string $mode, bool $queued): array
    {
        $result = $this->results[$email] ?? $this->results['*'] ?? 'valid';
        $now = now()->utc()->toIso8601ZuluString();

        return [
            'id' => (string) Str::ulid(),
            'email' => $email,
            'reference' => $reference ?? (string) Str::uuid(),
            'batch_id' => null,
            'mode' => $mode,
            'state' => $queued ? 'queued' : 'completed',
            'result' => $queued ? null : $result,
            'reason' => $queued ? null : ['valid' => 'accepted', 'invalid' => 'rejected'][$result] ?? 'greylisted',
            'final' => ! $queued,
            'cached' => false,
            'mx_host' => $queued ? null : 'mx.fake.test',
            'smtp_code' => $queued ? null : ['valid' => 250, 'invalid' => 550][$result] ?? 450,
            'smtp_response' => null,
            'checked_at' => $queued ? null : $now,
            'requested_at' => $now,
            'completed_at' => $queued ? null : $now,
        ];
    }
}
