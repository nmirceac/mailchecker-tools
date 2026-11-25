<?php

namespace MailcheckerTools\Tests;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use MailcheckerTools\MailcheckerToolsServiceProvider;
use MailcheckerTools\Tests\Fixtures\TestEmailCheck;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const SECRET = 'whsec_test_secret';

    protected const API = 'https://mailchecker.test/api/v2';

    protected function setUp(): void
    {
        parent::setUp();
        TestEmailCheck::$hooks = [];
    }

    protected function getPackageProviders($app): array
    {
        return [MailcheckerToolsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // SQLite in memory by default; set MAILCHECKER_TEST_DB=mysql (+ DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD)
        // to run against MySQL. Tables get an "mct_" prefix so a shared test database is never touched.
        if (getenv('MAILCHECKER_TEST_DB') === 'mysql') {
            $app['config']->set('database.connections.package', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => 'mct_',
            ]);
            $app['config']->set('database.default', 'package');
        } else {
            $app['config']->set('database.default', 'testing');
        }
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('mailchecker.api.url', self::API);
        $app['config']->set('mailchecker.api.token', 'test-token');
        $app['config']->set('mailchecker.api.retries', 1);
        $app['config']->set('mailchecker.webhook.secret', self::SECRET);
        $app['config']->set('mailchecker.models.check', TestEmailCheck::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::dropIfExists('mailchecker_checks');
        (include __DIR__.'/../database/migrations/create_mailchecker_checks_table.php.stub')->up();
    }

    /** @param  array<string, mixed>  $event */
    protected function postWebhook(array $event, ?string $secret = self::SECRET, ?int $timestamp = null): TestResponse
    {
        $event += ['id' => (string) Str::ulid(), 'created_at' => now()->toIso8601ZuluString(), 'application' => ['id' => 'app', 'name' => 'Test']];
        $body = (string) json_encode($event);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MAILCHECK_TIMESTAMP' => (string) ($timestamp ?? time()), 'HTTP_X_MAILCHECK_WEBHOOK_ID' => $event['id']];
        if ($secret !== null) {
            $headers['HTTP_X_MAILCHECK_SIGNATURE'] = hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/mailchecker/webhook', [], [], [], $headers, $body);
    }

    /**
     * A platform check resource.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function apiCheck(array $overrides = []): array
    {
        return $overrides + [
            'id' => '01kabc0000000000000000000a',
            'email' => 'someone@example.com',
            'reference' => null,
            'batch_id' => null,
            'mode' => 'sync',
            'state' => 'completed',
            'result' => 'valid',
            'reason' => 'accepted',
            'final' => true,
            'cached' => false,
            'mx_host' => 'mx.example.com',
            'smtp_code' => 250,
            'smtp_response' => '250 2.1.5 OK',
            'checked_at' => '2026-11-17T10:00:00Z',
            'requested_at' => '2026-11-17T10:00:00Z',
            'completed_at' => '2026-11-17T10:00:00Z',
            'catch_all' => false,
            'role' => false,
            'free' => false,
            'disposable' => false,
            'did_you_mean' => null,
        ];
    }
}
