<?php

namespace MailcheckerTools\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Facades\Mailcheck;
use MailcheckerTools\Tests\TestCase;

class ForexTest extends TestCase
{
    /** @return array<string, mixed> the platform's answer */
    private function apiRate(string $from = 'ZAR', string $to = 'USD', float $rate = 0.0625, string $date = '2026-11-24', array $meta = []): array
    {
        return [
            'data' => ['from' => $from, 'to' => $to, 'date' => $date, 'rate' => $rate],
            'meta' => ['cached' => true, 'stale' => false, 'fetched_at' => '2026-11-24T00:05:02Z', ...$meta],
        ];
    }

    public function test_a_rate_calls_the_api_and_returns_data_and_meta(): void
    {
        Http::fake([self::API.'/forex/*' => Http::response($this->apiRate())]);

        $rate = app(MailcheckClient::class)->exchangeRate(' zar', 'usd ');

        $this->assertSame(
            ['from' => 'ZAR', 'to' => 'USD', 'date' => '2026-11-24', 'rate' => 0.0625, 'cached' => true, 'stale' => false, 'fetched_at' => '2026-11-24T00:05:02Z'],
            $rate,
        );
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === self::API.'/forex/ZAR/USD'
            && $r->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_a_past_day_is_sent_as_the_date_query(): void
    {
        Http::fake([self::API.'/forex/*' => Http::response($this->apiRate('GBP', 'ZAR', 25, '2025-06-30'))]);

        Mailcheck::exchangeRate('GBP', 'ZAR', '2025-06-30');
        Mailcheck::exchangeRate('GBP', 'ZAR', Carbon::parse('2025-07-01 23:30:00'));

        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/forex/GBP/ZAR?date=2025-06-30');
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/forex/GBP/ZAR?date=2025-07-01');
    }

    public function test_convert_multiplies_by_the_rate(): void
    {
        Http::fake([self::API.'/forex/*' => Http::response($this->apiRate('USD', 'ZAR', 18.25))]);

        $this->assertSame(1825.0, Mailcheck::convert(100, 'USD', 'ZAR'));
    }

    public function test_rates_are_cached_locally_unless_stale(): void
    {
        Http::fake([
            self::API.'/forex/USD/ZAR' => Http::response($this->apiRate('USD', 'ZAR', 16)),
            self::API.'/forex/EUR/RON' => Http::response($this->apiRate('EUR', 'RON', 5, '2026-11-23', ['stale' => true])),
        ]);

        Mailcheck::exchangeRate('USD', 'ZAR');
        Mailcheck::exchangeRate('usd', 'zar');
        Mailcheck::exchangeRate('EUR', 'RON');
        Mailcheck::exchangeRate('EUR', 'RON');

        Http::assertSentCount(3);

        $uncached = new MailcheckClient(self::API, 'test-token', forexCacheMinutes: 0);
        $uncached->exchangeRate('USD', 'ZAR');
        $uncached->exchangeRate('USD', 'ZAR');
        Http::assertSentCount(5);
    }

    public function test_errors_become_exceptions(): void
    {
        Http::fake([
            self::API.'/forex/*' => Http::sequence()
                ->push(['error' => ['message' => 'Unsupported currency BRL.', 'status' => 422, 'errors' => ['from' => ['Unsupported currency BRL.']]]], 422)
                ->push(['error' => ['message' => 'Exchange rates are temporarily unavailable.', 'status' => 503]], 503, ['Retry-After' => '60'])
                ->push(['error' => ['message' => 'Exchange rates are temporarily unavailable.', 'status' => 503]], 503, ['Retry-After' => '60']),
        ]);

        try {
            Mailcheck::exchangeRate('BRL', 'USD');
            $this->fail('Expected an exception');
        } catch (MailcheckApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey('from', $e->errors);
        }

        try {
            Mailcheck::exchangeRate('USD', 'ZAR', '2001-01-01');
            $this->fail('Expected an exception');
        } catch (MailcheckApiException $e) {
            $this->assertSame(503, $e->status);
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_the_fake_answers_records_and_asserts(): void
    {
        $fake = Mailcheck::fake()->withExchangeRates([
            'USD/ZAR' => 18.2,
            'EUR/RON' => new MailcheckApiException('Exchange rates are temporarily unavailable.', 503),
        ]);
        $fake->assertNoExchangeRateRequests();

        $this->assertSame(18.2, Mailcheck::exchangeRate('USD', 'ZAR')['rate']);
        $this->assertSame(1 / 18.2, Mailcheck::exchangeRate('zar', 'usd')['rate']);
        $this->assertSame(1.5625, Mailcheck::exchangeRate('GBP', 'USD')['rate'], 'default: the platform fake rates');
        $this->assertSame(160.0, Mailcheck::exchangeRate('USD', 'JPY')['rate']);
        $this->assertSame(1.0, Mailcheck::exchangeRate('RON', 'RON')['rate']);
        $this->assertSame(1820.0, Mailcheck::convert(100, 'USD', 'ZAR'));
        $this->assertSame('2025-06-30', Mailcheck::exchangeRate('USD', 'ZAR', '2025-06-30')['date']);
        $this->assertThrows(fn () => Mailcheck::exchangeRate('EUR', 'RON'), MailcheckApiException::class);
        $this->assertThrows(fn () => Mailcheck::exchangeRate('BRL', 'USD'), MailcheckApiException::class);

        $fake->assertExchangeRateRequested('USD/ZAR', 3);
        $fake->assertExchangeRateRequested(fn (string $pair) => str_starts_with($pair, 'ZAR/'));
        $fake->assertExchangeRateRequested(null, 9);
        Http::assertNothingSent();
    }

    public function test_the_forex_command(): void
    {
        Mailcheck::fake()->withExchangeRates(['USD/ZAR' => 18.25, 'EUR/RON' => new MailcheckApiException('Exchange rates are temporarily unavailable.', 503)]);

        $this->artisan('mailchecker:forex USD ZAR')->expectsOutputToContain('1 USD = 18.25 ZAR on ')->assertSuccessful();
        $this->artisan('mailchecker:forex USD ZAR 100 --date=2025-06-30')->expectsOutputToContain('100 USD = 1825 ZAR on 2025-06-30')->assertSuccessful();
        $this->artisan('mailchecker:forex EUR RON')->expectsOutputToContain('temporarily unavailable')->assertFailed();
        $this->artisan('mailchecker:forex USD ZAR lots')->assertExitCode(2);
    }
}
