<?php

namespace MailcheckerTools\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Typed client for the Mail Checker API v2.
 *
 * Check arrays follow the API's check resource:
 *   id (ulid), email, reference, batch_id, mode (sync|async|batch), state (queued|waiting|completed),
 *   result (valid|invalid|unknown|null), reason, final, cached, mx_host, smtp_code, smtp_response,
 *   checked_at, requested_at, completed_at
 */
class MailcheckClient
{
    /** Addresses per batch call (the platform's limit). */
    public const BATCH_MAX = 100;

    public function __construct(
        protected ?string $baseUrl,
        protected ?string $token,
        protected int $timeout = 60,
        protected int $retries = 2,
    ) {}

    /**
     * Check one address. Sync (default): the result comes back now (up to ~45 s); when it is
     * "unknown" with final = false, a check.completed webhook follows. Async: returns at once
     * with state "queued"; the result arrives by webhook.
     *
     * Every call carries a reference (one is generated if you pass none): sending the same
     * reference again returns the original check (created = false) instead of checking again.
     *
     * @return array<string, mixed> check, plus "created" => bool
     */
    public function check(string $email, bool $async = false, ?string $reference = null, bool $force = false, ?int $maxAgeHours = null): array
    {
        $response = $this->request('post', 'checks', array_filter([
            'email' => $email,
            'async' => $async,
            'reference' => $reference ?? (string) Str::uuid(),
            'force' => $force ?: null,
            'max_age_hours' => $maxAgeHours,
        ], fn ($v) => $v !== null));

        return (array) $response->json('data') + ['created' => (bool) $response->json('created', true)];
    }

    /**
     * Check many addresses asynchronously: one check.completed webhook per address. Calls are
     * split into chunks of 100; results keep the input order (index = position in $items).
     *
     * @param  array<int, string|array{email: string, reference?: string|null}>  $items
     * @return list<array{index: int, created: bool|null, check: array<string, mixed>|null, errors: array<string, list<string>>|null, batch_id: string|null}>
     */
    public function checkBatch(array $items, bool $force = false): array
    {
        $items = array_values(array_map(fn ($item) => [
            'email' => is_array($item) ? (string) $item['email'] : (string) $item,
            'reference' => (is_array($item) ? ($item['reference'] ?? null) : null) ?? (string) Str::uuid(),
        ], $items));

        $results = [];
        foreach (array_chunk($items, self::BATCH_MAX) as $chunkNo => $chunk) {
            $response = $this->request('post', 'checks/batch', array_filter(['items' => $chunk, 'force' => $force ?: null], fn ($v) => $v !== null));
            $batchId = $response->json('batch_id');
            foreach ((array) $response->json('data') as $row) {
                $results[] = [
                    'index' => $chunkNo * self::BATCH_MAX + (int) $row['index'],
                    'created' => $row['created'] ?? null,
                    'check' => $row['check'] ?? null,
                    'errors' => $row['errors'] ?? null,
                    'batch_id' => is_string($batchId) ? $batchId : null,
                ];
            }
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function getCheck(string $id): array
    {
        return (array) $this->request('get', 'checks/'.rawurlencode($id))->json('data');
    }

    /**
     * @param  array{state?: string, result?: string, reference?: string, batch_id?: string, email?: string, from?: string, to?: string, per_page?: int, cursor?: string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function checks(array $filters = []): array
    {
        return $this->paginated('checks', $filters);
    }

    /** @return array{url: string|null, secret: string, events: list<string>} */
    public function webhook(): array
    {
        return $this->webhookSettings((array) $this->request('get', 'webhook')->json('data'));
    }

    /** @return array{url: string|null, secret: string, events: list<string>} */
    public function updateWebhook(?string $url, bool $rotateSecret = false): array
    {
        return $this->webhookSettings((array) $this->request('put', 'webhook', ['url' => $url, 'rotate_secret' => $rotateSecret])->json('data'));
    }

    /** @return array{ok: bool, status: int|null, error: string|null} */
    public function testWebhook(): array
    {
        $data = (array) $this->request('post', 'webhook/test')->json('data');

        return [
            'ok' => (bool) ($data['ok'] ?? false),
            'status' => isset($data['status']) ? (int) $data['status'] : null,
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{url: string|null, secret: string, events: list<string>}
     */
    protected function webhookSettings(array $data): array
    {
        return [
            'url' => isset($data['url']) ? (string) $data['url'] : null,
            'secret' => (string) ($data['secret'] ?? ''),
            'events' => array_values(array_map('strval', (array) ($data['events'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    protected function paginated(string $path, array $filters): array
    {
        $response = $this->request('get', $path, $filters);
        $cursor = $response->json('meta.next_cursor');

        return [
            'data' => array_values((array) $response->json('data')),
            'next_cursor' => is_string($cursor) ? $cursor : null,
        ];
    }

    /** @param array<string, mixed> $data */
    protected function request(string $method, string $path, array $data = []): Response
    {
        try {
            $response = $this->http()->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw MailcheckApiException::connection($e);
        } catch (RequestException $e) {
            $response = $e->response;
        }

        if (! $response->successful()) {
            throw MailcheckApiException::fromResponse($response);
        }

        return $response;
    }

    protected function http(): PendingRequest
    {
        if (blank($this->baseUrl)) {
            throw new MailcheckApiException('MAILCHECKER_API_URL is not configured.', 400);
        }
        if (blank($this->token)) {
            throw new MailcheckApiException('MAILCHECKER_API_TOKEN is not configured.', 401);
        }

        return Http::baseUrl(rtrim((string) $this->baseUrl, '/').'/')
            ->withToken((string) $this->token)
            ->acceptJson()
            ->asJson()
            ->withUserAgent('mailchecker-tools (Laravel)')
            ->timeout($this->timeout)
            ->connectTimeout(min(5, $this->timeout))
            ->retry(
                $this->retries + 1,
                fn (int $attempt) => 250 * 2 ** ($attempt - 1),
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
                throw: false,
            );
    }
}
