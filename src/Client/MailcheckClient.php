<?php

namespace MailcheckerTools\Client;

use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
 *
 * GeoIP answers (geoip()) carry geoiplookup.io's fields as the platform returns them:
 *   ip, isp, org, hostname, latitude, longitude, postal_code, city, country_code, country_name,
 *   continent_code, continent_name, region, district, timezone_name, connection_type, asn_number,
 *   asn_org, asn, currency_code, currency_name, language_code, language_name, success, premium
 *
 * Exchange rates (exchangeRate()): from, to, date (the UTC day of the rate), rate (how many `to`
 * one `from` buys), cached, stale, fetched_at.
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
        protected int $geoipTimeout = 10,
        protected int $geoipCacheMinutes = 0,
        protected int $forexTimeout = 10,
        protected int $forexCacheMinutes = 0,
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
     * Look up the location of an IPv4 or IPv6 address (token ability geoip:lookup). `data` is
     * geoiplookup.io's answer; `data.success` is false when there is no location (always for
     * private and reserved addresses). The platform caches answers for 30 days; `stale` is true
     * when geoiplookup.io was down and an older answer was served. Answers with a location are
     * also kept in your default cache for mailchecker.geoip.cache_minutes.
     *
     * @return array{data: array<string, mixed>, cached: bool, stale: bool, fetched_at: string|null}
     *
     * @throws MailcheckApiException 422 for an invalid address, 503 while geoiplookup.io is unavailable
     */
    public function geoip(string $ip): array
    {
        $ip = strtolower(trim($ip));
        $key = 'mailchecker-tools:geoip:'.$ip;
        if ($this->geoipCacheMinutes > 0 && is_array($cached = Cache::get($key))) {
            /** @var array{data: array<string, mixed>, cached: bool, stale: bool, fetched_at: string|null} $cached */
            return $cached;
        }

        // An address only has [0-9a-f:.]; anything else is encoded and refused by the platform (422).
        $path = 'geoip/'.(preg_match('/^[0-9a-f:.]+$/', $ip) === 1 ? $ip : rawurlencode($ip));
        $response = $this->request('get', $path, timeout: $this->geoipTimeout);
        $fetchedAt = $response->json('meta.fetched_at');

        $result = [
            'data' => (array) $response->json('data'),
            'cached' => (bool) $response->json('meta.cached', false),
            'stale' => (bool) $response->json('meta.stale', false),
            'fetched_at' => is_string($fetchedAt) ? $fetchedAt : null,
        ];

        if ($this->geoipCacheMinutes > 0 && ($result['data']['success'] ?? false) === true && ! $result['stale']) {
            Cache::put($key, $result, now()->addMinutes($this->geoipCacheMinutes));
        }

        return $result;
    }

    /**
     * How many $to one $from buys (token ability forex:read). Currencies: AUD, CAD, CHF, EUR, GBP, JPY, RON, SEK, USD, ZAR.
     * The platform fetches the rates from Fixer once a day (shortly after 00:00 UTC) and keeps
     * them; `date` is the UTC day of the rate. `stale` is true when today's rates weren't
     * available and an earlier day's were returned. Answers that aren't stale are also kept in
     * your default cache for mailchecker.forex.cache_minutes.
     *
     * @param  DateTimeInterface|string|null  $date  the day (Y-m-d); null for today
     * @return array{from: string, to: string, date: string, rate: float, cached: bool, stale: bool, fetched_at: string|null}
     *
     * @throws MailcheckApiException 422 for an unsupported currency or a bad date, 503 while Fixer is unavailable
     */
    public function exchangeRate(string $from, string $to, DateTimeInterface|string|null $date = null): array
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : (blank($date) ? null : trim($date));

        $key = "mailchecker-tools:forex:{$from}:{$to}:".($date ?? 'today');
        if ($this->forexCacheMinutes > 0 && is_array($cached = Cache::get($key))) {
            /** @var array{from: string, to: string, date: string, rate: float, cached: bool, stale: bool, fetched_at: string|null} $cached */
            return $cached;
        }

        $path = 'forex/'.rawurlencode($from).'/'.rawurlencode($to);
        $response = $this->request('get', $path, $date === null ? [] : ['date' => $date], timeout: $this->forexTimeout);
        $fetchedAt = $response->json('meta.fetched_at');

        $result = [
            'from' => (string) $response->json('data.from', $from),
            'to' => (string) $response->json('data.to', $to),
            'date' => (string) $response->json('data.date', $date ?? ''),
            'rate' => (float) $response->json('data.rate'),
            'cached' => (bool) $response->json('meta.cached', false),
            'stale' => (bool) $response->json('meta.stale', false),
            'fetched_at' => is_string($fetchedAt) ? $fetchedAt : null,
        ];

        if ($this->forexCacheMinutes > 0 && ! $result['stale'] && $result['rate'] > 0) {
            Cache::put($key, $result, now()->addMinutes($this->forexCacheMinutes));
        }

        return $result;
    }

    /**
     * $amount in $from, converted to $to at that day's rate (not rounded: round to your currency's
     * minor unit yourself).
     *
     * @param  DateTimeInterface|string|null  $date  the day (Y-m-d); null for today
     *
     * @throws MailcheckApiException see exchangeRate()
     */
    public function convert(float|int $amount, string $from, string $to, DateTimeInterface|string|null $date = null): float
    {
        return $amount * $this->exchangeRate($from, $to, $date)['rate'];
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

    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $timeout  seconds, instead of the default (sized for a sync check)
     */
    protected function request(string $method, string $path, array $data = [], ?int $timeout = null): Response
    {
        try {
            $response = $this->http($timeout)->{$method}($path, $data);
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

    protected function http(?int $timeout = null): PendingRequest
    {
        $timeout ??= $this->timeout;

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
            ->timeout($timeout)
            ->connectTimeout(min(5, $timeout))
            ->retry(
                $this->retries + 1,
                fn (int $attempt) => 250 * 2 ** ($attempt - 1),
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
                throw: false,
            );
    }
}
