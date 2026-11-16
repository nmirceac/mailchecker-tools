<?php

namespace MailcheckerTools\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Tests\TestCase;

class ClientTest extends TestCase
{
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
}
