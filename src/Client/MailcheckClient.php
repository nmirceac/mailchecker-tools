<?php

namespace MailcheckerTools\Client;

use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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
 *   checked_at, requested_at, completed_at,
 *   catch_all (the domain accepts every address: the result is then unknown, reason accept_all; null when not known),
 *   role (info@, support@, …), free (a personal mailbox from a public provider or consumer ISP),
 *   disposable (a throwaway provider), did_you_mean (the address with a mistyped domain corrected, or null)
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

    /** Seconds a URL that couldn't be reached (or answered 502/503/504) is tried last. */
    public const DOWN_SECONDS = 60;

    /** The WeAnswer Mail Checker workers: used when MAILCHECKER_HOSTS is not set (or empty). */
    public const DEFAULT_HOSTS = ['ck1.weanswer.it', 'ck2.weanswer.it'];

    /** Where the API lives on every host. */
    public const API_PATH = '/api/v2';

    /** @var list<string> the API URLs, one per Mail Checker worker (https://<host>/api/v2) */
    protected array $baseUrls;

    /**
     * @param  string|list<string>|null  $hosts  the Mail Checker workers: one host, several separated by commas,
     *                                           or a list (see apiUrls()); a call that can't reach one, or gets a
     *                                           server error from it, goes to the next
     * @param  bool  $spread  with several hosts, start each call at a random one (false: always in the order given)
     */
    public function __construct(
        string|array|null $hosts,
        protected ?string $token,
        protected int $timeout = 10,
        protected int $retries = 2,
        protected int $geoipTimeout = 10,
        protected int $geoipCacheMinutes = 0,
        protected int $forexTimeout = 10,
        protected int $forexCacheMinutes = 0,
        protected bool $spread = true,
        protected int $connectTimeout = 1,
    ) {
        $this->baseUrls = self::apiUrls($hosts);
    }

    /**
     * The API URL of each host: "ck1.weanswer.it" → https://ck1.weanswer.it/api/v2. A port is kept
     * ("ck1.weanswer.it:8443"), and so is a scheme other than https ("http://127.0.0.1:8001" for a local
     * Mail Checker); any path is ignored. Blank and repeated entries are dropped.
     *
     * @param  string|array<array-key, mixed>|null  $hosts
     * @return list<string>
     */
    public static function apiUrls(string|array|null $hosts): array
    {
        $urls = [];
        foreach (is_array($hosts) ? $hosts : explode(',', (string) $hosts) as $entry) {
            $entry = trim(is_string($entry) ? $entry : '');
            $parts = $entry === '' ? false : parse_url(str_contains($entry, '://') ? $entry : 'https://'.$entry);
            if (! is_array($parts) || ($parts['host'] ?? '') === '') {
                continue;
            }

            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            $urls[] = strtolower($parts['scheme'] ?? 'https').'://'.strtolower($parts['host']).$port.self::API_PATH;
        }

        return array_values(array_unique($urls));
    }

    /** @return list<string> the API URL of each configured host, in the order given */
    public function urls(): array
    {
        return $this->baseUrls;
    }

    /** The same client, talking to one host only (e.g. to test each Mail Checker worker). */
    public function forHost(string $host): static
    {
        $client = clone $this;
        $client->baseUrls = self::apiUrls($host);

        return $client;
    }

    /**
     * Check one address. Sync (default): the result comes back now (usually in a few seconds, at most
     * the client's timeout); when it is
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
     * The platform fixes each day's rate once, at 08:00 UTC, and replaces it with the day's close
     * after midnight; before 08:00 UTC "today" is the previous day. `date` is the UTC day of the rate. `stale` is true when today's rates weren't
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
     * One API call, with failover. A connection error or a 5xx is the worker's problem: with several hosts
     * the call goes to the next one at once, and each is asked once (ck1, then ck2, then the call fails);
     * with one host it is retried `retries` times. A host that couldn't be reached, or answered 502/503/504,
     * is tried last for DOWN_SECONDS. A 429 waits and asks the same URL again, up to `retries` times (the
     * limit is per application on every worker); other 4xx are final. Every attempt sends the same body,
     * so a check retried on another worker carries the same reference and isn't made twice.
     *
     * @param  array<string, mixed>  $data
     * @param  int|null  $timeout  seconds, instead of the default (sized for a sync check)
     */
    protected function request(string $method, string $path, array $data = [], ?int $timeout = null): Response
    {
        if ($this->baseUrls === []) {
            throw new MailcheckApiException('MAILCHECKER_HOSTS has no valid host.', 400);
        }
        if (blank($this->token)) {
            throw new MailcheckApiException('MAILCHECKER_API_TOKEN is not configured.', 401);
        }

        $order = $this->attemptOrder();
        $index = 0;
        $retriesLeft = $this->retries;
        $backoff = 0;
        $last = null;

        while (true) {
            $baseUrl = $order[$index];
            $last = $this->attempt($baseUrl, $method, $path, $data, $timeout);
            if ($last instanceof Response && $last->successful()) {
                return $last;
            }

            // The worker's problem (no connection, or a 5xx), rather than this request's or the rate limit's.
            $workerFailed = ! $last instanceof Response || $last->serverError();
            if (! $last instanceof Response || in_array($last->status(), [502, 503, 504], true)) {
                $this->markDown($baseUrl);
            }

            if ($workerFailed && $index + 1 < count($order)) {
                $index++;   // the next worker, at once

                continue;
            }

            $rateLimited = $last instanceof Response && $last->status() === 429;
            if (($rateLimited || ($workerFailed && count($order) === 1)) && $retriesLeft-- > 0) {
                usleep((int) (250_000 * 2 ** $backoff++));   // 250 ms, 500 ms, … then the same URL again

                continue;
            }

            break;
        }

        /** @var Response|ConnectionException $last (at least one attempt was made) */
        throw $last instanceof Response ? MailcheckApiException::fromResponse($last) : MailcheckApiException::connection($last);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function attempt(string $baseUrl, string $method, string $path, array $data, ?int $timeout): Response|ConnectionException
    {
        try {
            return $this->http($baseUrl, $timeout)->{$method}($path, $data);
        } catch (ConnectionException $e) {
            return $e;
        }
    }

    /**
     * The URLs in the order this call tries them: from a random one when spreading, those that failed
     * recently last.
     *
     * @return non-empty-list<string>
     */
    protected function attemptOrder(): array
    {
        $urls = $this->baseUrls;
        if ($this->spread && count($urls) > 1) {
            $start = random_int(0, count($urls) - 1);
            $urls = [...array_slice($urls, $start), ...array_slice($urls, 0, $start)];
        }

        $up = array_values(array_filter($urls, fn (string $url) => ! $this->isDown($url)));
        $down = array_values(array_filter($urls, fn (string $url) => $this->isDown($url)));

        /** @var non-empty-list<string> */
        return [...$up, ...$down];
    }

    protected function isDown(string $url): bool
    {
        try {
            return Cache::has($this->downKey($url));
        } catch (Throwable) {
            return false;
        }
    }

    protected function markDown(string $url): void
    {
        if (count($this->baseUrls) < 2) {
            return;
        }

        try {
            Cache::put($this->downKey($url), true, now()->addSeconds(self::DOWN_SECONDS));
        } catch (Throwable) {
            // Without a working cache every call simply starts from the top again.
        }
    }

    protected function downKey(string $url): string
    {
        return 'mailchecker-tools:down:'.sha1($url);
    }

    protected function http(string $baseUrl, ?int $timeout = null): PendingRequest
    {
        $timeout ??= $this->timeout;

        return Http::baseUrl($baseUrl.'/')
            ->withToken((string) $this->token)
            ->acceptJson()
            ->asJson()
            ->withUserAgent('mailchecker-tools (Laravel)')
            ->timeout($timeout)
            ->connectTimeout(max(1, min($this->connectTimeout, $timeout)));
    }
}
