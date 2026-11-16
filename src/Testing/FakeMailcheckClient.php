<?php

namespace MailcheckerTools\Testing;

use Closure;
use Illuminate\Support\Str;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;
use PHPUnit\Framework\Assert;

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
