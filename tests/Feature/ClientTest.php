<?php

namespace MailcheckerTools\Tests\Feature;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Tests\TestCase;

class ClientTest extends TestCase
{
    private const CK1 = 'https://ck1.mailchecker.test/api/v2';

    private const CK2 = 'https://ck2.mailchecker.test/api/v2';

    public function test_a_sync_check_sends_a_reference_and_returns_the_result(): void
    {
        Http::fake([self::API.'/checks' => Http::response(['data' => $this->apiCheck(), 'created' => true])]);

        $check = app(MailcheckClient::class)->check('someone@example.com');

        $this->assertSame('valid', $check['result']);
        $this->assertTrue($check['created']);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/checks'
            && $r->hasHeader('Authorization', 'Bearer test-token')
            && $r['email'] === 'someone@example.com'
            && $r['async'] === false
            && preg_match('/^[0-9a-f-]{36}$/', (string) $r['reference']) === 1);
    }

    public function test_a_retry_after_a_server_error_reuses_the_reference(): void
    {
        Http::fake([self::API.'/checks' => Http::sequence()->push(['error' => ['message' => 'Server error.', 'status' => 500]], 500)
            ->push(['data' => $this->apiCheck(), 'created' => true])]);

        app(MailcheckClient::class)->check('someone@example.com', reference: '7b0f3c9e-1d2a-4f5b-8c6d-9e0f1a2b3c4d');

        $references = collect(Http::recorded())->map(fn ($pair) => $pair[0]['reference'])->unique();
        $this->assertSame(['7b0f3c9e-1d2a-4f5b-8c6d-9e0f1a2b3c4d'], $references->values()->all());
        Http::assertSentCount(2);
    }

    public function test_api_errors_become_exceptions_with_status_and_field_errors(): void
    {
        Http::fake([self::API.'/checks' => Http::response(['error' => ['message' => 'The email field must be a valid email address.', 'status' => 422, 'errors' => ['email' => ['invalid']]]], 422)]);

        try {
            app(MailcheckClient::class)->check('nope');
            $this->fail('Expected an exception');
        } catch (MailcheckApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(['email' => ['invalid']], $e->errors);
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_batches_are_split_by_one_hundred_and_keep_the_input_order(): void
    {
        Http::fake([self::API.'/checks/batch' => function (Request $r) {
            $items = (array) $r['items'];

            return Http::response(['batch_id' => 'B'.count($items), 'data' => array_map(fn ($item, $i) => [
                'index' => $i, 'created' => true, 'check' => $this->apiCheck(['email' => $item['email'], 'reference' => $item['reference'], 'state' => 'queued', 'result' => null, 'final' => false]), 'errors' => null,
            ], $items, array_keys($items))], 202);
        }]);

        $emails = array_map(fn ($i) => "a{$i}@example.com", range(0, 149));
        $results = app(MailcheckClient::class)->checkBatch($emails);

        Http::assertSentCount(2);
        $this->assertCount(150, $results);
        $this->assertSame(149, $results[149]['index']);
        $this->assertSame('a149@example.com', $results[149]['check']['email']);
        $this->assertSame('B50', $results[149]['batch_id']);
    }

    public function test_a_missing_token_is_reported(): void
    {
        config(['mailchecker.api.token' => null]);
        app()->forgetInstance(MailcheckClient::class);

        $this->expectException(MailcheckApiException::class);
        $this->expectExceptionMessage('MAILCHECKER_API_TOKEN is not configured.');
        app(MailcheckClient::class)->check('someone@example.com');
    }

    public function test_hosts_become_api_urls(): void
    {
        $this->assertSame([self::CK1, self::CK2], MailcheckClient::apiUrls(' ck1.mailchecker.test , CK2.mailchecker.test,,ck1.mailchecker.test'));
        $this->assertSame(
            ['https://ck1.mailchecker.test:8443/api/v2', 'http://127.0.0.1:8001/api/v2', self::CK2],
            MailcheckClient::apiUrls(['ck1.mailchecker.test:8443', 'http://127.0.0.1:8001', 'https://ck2.mailchecker.test/api/v2/']),
        );
        $this->assertSame([self::API], app(MailcheckClient::class)->urls());
        $this->assertSame([], MailcheckClient::apiUrls(null));
        $this->assertSame([], MailcheckClient::apiUrls(' , :8080'));
    }

    public function test_without_hosts_the_weanswer_workers_are_used(): void
    {
        foreach ([null, ''] as $hosts) {
            config(['mailchecker.api.hosts' => $hosts]);
            app()->forgetInstance(MailcheckClient::class);

            $this->assertSame(['https://ck1.weanswer.it/api/v2', 'https://ck2.weanswer.it/api/v2'], app(MailcheckClient::class)->urls());
        }
    }

    public function test_each_worker_is_asked_once_then_the_call_fails(): void
    {
        Http::fake([self::CK1.'/*' => $this->refused(), self::CK2.'/*' => Http::response(['error' => ['message' => 'Server error.', 'status' => 500]], 500)]);

        try {
            $this->twoWorkers()->check('someone@example.com');
            $this->fail('Expected an exception');
        } catch (MailcheckApiException $e) {
            $this->assertSame(500, $e->status);   // the last worker's answer
        }
        $this->assertSame([self::CK1.'/checks', self::CK2.'/checks'], $this->sentUrls());
    }

    public function test_a_single_url_is_retried_after_a_connection_error_and_not_marked_down(): void
    {
        Http::fake([self::API.'/checks' => $this->refused()]);

        try {
            app(MailcheckClient::class)->check('someone@example.com');
            $this->fail('Expected an exception');
        } catch (MailcheckApiException $e) {
            $this->assertSame(0, $e->status);
            $this->assertTrue($e->isRetryable());
        }

        Http::assertSentCount(2);   // retries = 1 in the tests
        $this->assertFalse(Cache::has('mailchecker-tools:down:'.sha1(self::API)));
    }

    public function test_a_connection_error_fails_over_to_the_next_url_which_is_then_asked_first(): void
    {
        Http::fake([self::CK1.'/*' => $this->refused(), self::CK2.'/*' => Http::response(['data' => $this->apiCheck(), 'created' => true])]);
        $client = $this->twoWorkers();

        $this->assertSame('valid', $client->check('someone@example.com', reference: 'ref-1')['result']);
        $this->assertSame([self::CK1.'/checks', self::CK2.'/checks'], $this->sentUrls());
        $this->assertSame(['ref-1'], collect(Http::recorded())->map(fn ($pair) => $pair[0]['reference'])->unique()->values()->all());

        // ck1 is tried last for a minute: the next call goes straight to ck2.
        $client->check('other@example.com');
        $this->assertSame([self::CK1.'/checks', self::CK2.'/checks', self::CK2.'/checks'], $this->sentUrls());
    }

    public function test_a_server_error_fails_over_to_the_next_url(): void
    {
        Http::fake([
            self::CK1.'/*' => Http::response(['error' => ['message' => 'Server error.', 'status' => 500]], 500),
            self::CK2.'/*' => Http::response(['data' => $this->apiCheck(), 'created' => true]),
        ]);
        $client = $this->twoWorkers();

        $this->assertSame('valid', $client->check('someone@example.com')['result']);
        $this->assertSame([self::CK1.'/checks', self::CK2.'/checks'], $this->sentUrls());

        // A 500 may be this request's own problem: ck1 is not set aside.
        $client->check('other@example.com');
        $this->assertSame(self::CK1.'/checks', $this->sentUrls()[2]);
    }

    public function test_a_worker_that_answers_503_is_tried_last(): void
    {
        Http::fake([
            self::CK1.'/*' => Http::response(['error' => ['message' => 'Service Unavailable', 'status' => 503]], 503),
            self::CK2.'/*' => Http::response(['data' => $this->apiCheck(), 'created' => true]),
        ]);
        $client = $this->twoWorkers();

        $client->check('someone@example.com');
        $client->check('other@example.com');

        $this->assertSame([self::CK1.'/checks', self::CK2.'/checks', self::CK2.'/checks'], $this->sentUrls());
    }

    public function test_client_errors_do_not_fail_over(): void
    {
        Http::fake([
            self::CK1.'/*' => Http::response(['error' => ['message' => 'The email field must be a valid email address.', 'status' => 422]], 422),
            self::CK2.'/*' => Http::response(['data' => $this->apiCheck(), 'created' => true]),
        ]);

        try {
            $this->twoWorkers()->check('nope');
            $this->fail('Expected an exception');
        } catch (MailcheckApiException $e) {
            $this->assertSame(422, $e->status);
        }
        $this->assertSame([self::CK1.'/checks'], $this->sentUrls());
    }

    public function test_a_rate_limit_waits_and_asks_the_same_url_again(): void
    {
        // The limit is per application on every worker: asking ck2 instead wouldn't help.
        Http::fake([
            self::CK1.'/*' => Http::sequence()->push(['error' => ['message' => 'Too Many Attempts.', 'status' => 429]], 429)
                ->push(['data' => $this->apiCheck(), 'created' => true]),
            self::CK2.'/*' => Http::response(['data' => $this->apiCheck(), 'created' => true]),
        ]);

        $this->twoWorkers()->check('someone@example.com');

        $this->assertSame([self::CK1.'/checks', self::CK1.'/checks'], $this->sentUrls());
    }

    public function test_a_rate_limit_is_retried_at_most_retries_times(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Too Many Attempts.', 'status' => 429]], 429)]);

        try {
            $this->twoWorkers()->check('someone@example.com');
            $this->fail('Expected an exception');
        } catch (MailcheckApiException $e) {
            $this->assertSame(429, $e->status);
        }
        $this->assertSame([self::CK1.'/checks', self::CK1.'/checks'], $this->sentUrls());   // retries = 1 in the tests
    }

    public function test_when_every_url_is_down_they_are_still_tried(): void
    {
        Cache::put('mailchecker-tools:down:'.sha1(self::CK1), true, 60);
        Cache::put('mailchecker-tools:down:'.sha1(self::CK2), true, 60);
        Http::fake([self::CK1.'/*' => $this->refused(), self::CK2.'/*' => Http::response(['data' => $this->apiCheck(), 'created' => true])]);

        $this->assertSame('valid', $this->twoWorkers()->check('someone@example.com')['result']);
        $this->assertSame([self::CK1.'/checks', self::CK2.'/checks'], $this->sentUrls());
    }

    public function test_geoip_and_exchange_rates_fail_over_too(): void
    {
        Http::fake([
            self::CK1.'/*' => $this->refused(),
            self::CK2.'/geoip/*' => Http::response(['data' => ['ip' => '8.8.8.8', 'success' => true], 'meta' => ['cached' => true, 'stale' => false, 'fetched_at' => null]]),
            self::CK2.'/forex/*' => Http::response(['data' => ['from' => 'USD', 'to' => 'ZAR', 'date' => '2026-11-26', 'rate' => 17.5], 'meta' => ['cached' => true, 'stale' => false, 'fetched_at' => null]]),
        ]);
        $client = $this->twoWorkers();

        $this->assertTrue($client->geoip('8.8.8.8')['data']['success']);
        $this->assertSame(17.5, $client->exchangeRate('USD', 'ZAR')['rate']);
    }

    public function test_spreading_uses_every_url(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->apiCheck(), 'created' => true])]);
        $client = $this->twoWorkers(spread: true);

        for ($i = 0; $i < 40; $i++) {
            $client->check("a{$i}@example.com");
        }

        $hosts = collect($this->sentUrls())->countBy(fn (string $url) => parse_url($url, PHP_URL_HOST));
        $this->assertSame(40, $hosts->sum());
        $this->assertCount(2, $hosts);   // all 40 on one worker: 1 in 2^39
    }

    public function test_requests_connect_within_a_second_and_end_within_ten(): void
    {
        $client = app(MailcheckClient::class);
        $options = fn (?int $timeout) => (fn () => $this->http($this->baseUrls[0], $timeout)->getOptions())->call($client);

        $this->assertSame(1, $options(null)['connect_timeout']);
        $this->assertSame(10, $options(null)['timeout']);
        $this->assertSame(1, $options(10)['connect_timeout']);   // geoip and forex (their own 10 s)
    }

    private function twoWorkers(bool $spread = false): MailcheckClient
    {
        config(['mailchecker.api.hosts' => 'ck1.mailchecker.test,ck2.mailchecker.test', 'mailchecker.api.spread' => $spread]);
        app()->forgetInstance(MailcheckClient::class);

        return app(MailcheckClient::class);
    }

    /** @return list<string> */
    private function sentUrls(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->values()->all();
    }

    /** A worker that can't be reached (what Http::failedConnection() does from Laravel 11). */
    private function refused(): Closure
    {
        return fn (Request $request) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $request->toPsrRequest()));
    }
}
