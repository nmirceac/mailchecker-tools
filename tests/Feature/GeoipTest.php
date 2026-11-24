<?php

namespace MailcheckerTools\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Facades\Mailcheck;
use MailcheckerTools\Testing\FakeMailcheckClient;
use MailcheckerTools\Tests\TestCase;

class GeoipTest extends TestCase
{
    /** @param  array<string, mixed>  $data */
    private function apiGeoip(array $data = [], array $meta = []): array
    {
        return [
            'data' => FakeMailcheckClient::geoipAnswer('81.196.40.181', ['city' => 'Deva', 'country_code' => 'RO', ...$data]),
            'meta' => ['cached' => false, 'stale' => false, 'fetched_at' => '2026-11-24T13:19:14Z', ...$meta],
        ];
    }

    public function test_a_lookup_calls_the_api_and_returns_data_and_meta(): void
    {
        Http::fake([self::API.'/geoip/*' => Http::response($this->apiGeoip())]);

        $answer = app(MailcheckClient::class)->geoip(' 81.196.40.181 ');

        $this->assertSame('Deva', $answer['data']['city']);
        $this->assertTrue($answer['data']['success']);
        $this->assertFalse($answer['cached']);
        $this->assertFalse($answer['stale']);
        $this->assertSame('2026-11-24T13:19:14Z', $answer['fetched_at']);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === self::API.'/geoip/81.196.40.181'
            && $r->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_ipv6_addresses_go_in_the_path_as_they_are(): void
    {
        Http::fake([self::API.'/geoip/*' => Http::response($this->apiGeoip(['ip' => '2a00:1450:4001:80b::200e']))]);

        Mailcheck::geoip('2A00:1450:4001:80B::200E');

        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/geoip/2a00:1450:4001:80b::200e');
    }

    public function test_answers_with_a_location_are_cached_locally_others_are_not(): void
    {
        Http::fake([
            self::API.'/geoip/81.196.40.181' => Http::response($this->apiGeoip()),
            self::API.'/geoip/10.0.0.1' => Http::response($this->apiGeoip(['ip' => '10.0.0.1', 'success' => false], ['fetched_at' => null])),
            self::API.'/geoip/8.8.8.8' => Http::response($this->apiGeoip(['ip' => '8.8.8.8'], ['stale' => true, 'cached' => true])),
        ]);

        foreach (['81.196.40.181', '10.0.0.1', '8.8.8.8'] as $ip) {
            Mailcheck::geoip($ip);
            Mailcheck::geoip($ip);
        }

        Http::assertSentCount(5); // the located, fresh answer was reused once
    }

    public function test_the_local_cache_can_be_turned_off(): void
    {
        Http::fake([self::API.'/geoip/*' => Http::response($this->apiGeoip())]);
        $client = new MailcheckClient(self::API, 'test-token', geoipCacheMinutes: 0);

        $client->geoip('81.196.40.181');
        $client->geoip('81.196.40.181');

        Http::assertSentCount(2);
    }

    public function test_errors_become_exceptions(): void
    {
        Http::fake([
            self::API.'/geoip/*' => Http::sequence()
                ->push(['error' => ['message' => 'The ip must be a valid IPv4 or IPv6 address.', 'status' => 422, 'errors' => ['ip' => ['invalid']]]], 422)
                ->push(['error' => ['message' => 'GeoIP lookup is temporarily unavailable.', 'status' => 503]], 503, ['Retry-After' => '60'])
                ->push(['error' => ['message' => 'GeoIP lookup is temporarily unavailable.', 'status' => 503]], 503, ['Retry-After' => '60']),
        ]);

        try {
            Mailcheck::geoip('not an ip');
            $this->fail('Expected an exception');
        } catch (MailcheckApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(['ip' => ['invalid']], $e->errors);
        }
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/geoip/not%20an%20ip');

        try {
            Mailcheck::geoip('8.8.8.8');
            $this->fail('Expected an exception');
        } catch (MailcheckApiException $e) {
            $this->assertSame(503, $e->status);
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_the_fake_answers_records_and_asserts(): void
    {
        $fake = Mailcheck::fake()->withGeoip([
            '81.196.40.181' => ['city' => 'Deva', 'country_code' => 'RO'],
            '10.0.0.1' => ['success' => false, 'city' => '', 'country_code' => ''],
            '203.0.113.9' => new MailcheckApiException('GeoIP lookup is temporarily unavailable.', 503),
        ]);
        $fake->assertNoGeoipLookups();

        $this->assertSame('Deva', Mailcheck::geoip('81.196.40.181')['data']['city']);
        $this->assertFalse(Mailcheck::geoip('10.0.0.1')['data']['success']);
        $this->assertSame('Cape Town', Mailcheck::geoip('8.8.8.8')['data']['city']);
        $this->assertThrows(fn () => Mailcheck::geoip('203.0.113.9'), MailcheckApiException::class);

        $fake->assertGeoipLookedUp('81.196.40.181');
        $fake->assertGeoipLookedUp(fn (string $ip) => str_starts_with($ip, '10.'));
        $fake->assertGeoipLookedUp(null, 4);
        Http::assertNothingSent();
    }

    public function test_the_geoip_command(): void
    {
        Mailcheck::fake()->withGeoip(['81.196.40.181' => ['city' => 'Deva'], '203.0.113.9' => new MailcheckApiException('GeoIP lookup is temporarily unavailable.', 503)]);

        $this->artisan('mailchecker:geoip 81.196.40.181')->expectsOutputToContain('Deva')->assertSuccessful();
        $this->artisan('mailchecker:geoip 203.0.113.9')->expectsOutputToContain('temporarily unavailable')->assertFailed();
    }
}
