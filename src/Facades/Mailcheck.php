<?php

namespace MailcheckerTools\Facades;

use Illuminate\Support\Facades\Facade;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Testing\FakeMailcheckClient;

/**
 * @method static array<string, mixed> check(string $email, bool $async = false, ?string $reference = null, bool $force = false, ?int $maxAgeHours = null)
 * @method static list<array{index: int, created: bool|null, check: array<string, mixed>|null, errors: array<string, list<string>>|null, batch_id: string|null}> checkBatch(array<int, string|array{email: string, reference?: string|null}> $items, bool $force = false)
 * @method static array<string, mixed> getCheck(string $id)
 * @method static array{data: list<array<string, mixed>>, next_cursor: string|null} checks(array<string, mixed> $filters = [])
 * @method static array{url: string|null, secret: string, events: list<string>} webhook()
 * @method static array{url: string|null, secret: string, events: list<string>} updateWebhook(?string $url, bool $rotateSecret = false)
 * @method static array{ok: bool, status: int|null, error: string|null} testWebhook()
 * @method static array{data: array<string, mixed>, cached: bool, stale: bool, fetched_at: string|null} geoip(string $ip)
 * @method static array{from: string, to: string, date: string, rate: float, cached: bool, stale: bool, fetched_at: string|null} exchangeRate(string $from, string $to, \DateTimeInterface|string|null $date = null)
 * @method static float convert(float|int $amount, string $from, string $to, \DateTimeInterface|string|null $date = null)
 *
 * @see MailcheckClient
 */
class Mailcheck extends Facade
{
    /**
     * Swap in a fake client: no HTTP. Results per address, '*' for the rest (default valid):
     *
     *     Mailcheck::fake(['bad@example.com' => 'invalid', '*' => 'valid']);
     *     Mailcheck::fake()->withGeoip(['81.196.40.181' => ['city' => 'Deva', 'country_code' => 'RO']]);
     *     Mailcheck::fake()->withExchangeRates(['USD/ZAR' => 18.2]);
     *
     * @param  array<string, string>  $results
     */
    public static function fake(array $results = []): FakeMailcheckClient
    {
        static::swap($fake = new FakeMailcheckClient($results));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return MailcheckClient::class;
    }
}
