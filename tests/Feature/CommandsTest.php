<?php

namespace MailcheckerTools\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Facades\Mailcheck;
use MailcheckerTools\Tests\Fixtures\TestEmailCheck;
use MailcheckerTools\Tests\TestCase;

class CommandsTest extends TestCase
{
    public function test_setup_registers_the_webhook_and_tests_it(): void
    {
        Http::fake([
            self::API.'/webhook' => Http::sequence()
                ->push(['data' => ['url' => null, 'secret' => self::SECRET, 'events' => ['check.completed']]])
                ->push(['data' => ['url' => 'http://localhost/mailchecker/webhook', 'secret' => self::SECRET, 'events' => ['check.completed']]]),
            self::API.'/webhook/test' => Http::response(['data' => ['ok' => true, 'status' => 200, 'error' => null]]),
        ]);

        $this->artisan('mailchecker:setup')
            ->expectsOutputToContain('Webhook URL set to http://localhost/mailchecker/webhook')
            ->expectsOutputToContain('Test event delivered')
            ->assertSuccessful();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['url'] === 'http://localhost/mailchecker/webhook');
    }

    public function test_setup_prints_a_new_secret(): void
    {
        Http::fake([self::API.'/webhook' => Http::response(['data' => ['url' => 'http://localhost/mailchecker/webhook', 'secret' => 'whsec_other', 'events' => []]])]);
        $this->artisan('mailchecker:setup --skip-test')->expectsOutputToContain('MAILCHECKER_WEBHOOK_SECRET=whsec_other')->assertSuccessful();
    }

    public function test_setup_asks_every_host_and_fails_when_one_is_down(): void
    {
        $ck1 = 'https://ck1.mailchecker.test/api/v2';
        $ck2 = 'https://ck2.mailchecker.test/api/v2';
        config(['mailchecker.api.hosts' => 'ck1.mailchecker.test,ck2.mailchecker.test', 'mailchecker.api.spread' => false]);
        $this->app->forgetInstance(MailcheckClient::class);
        $webhook = ['data' => ['url' => 'http://localhost/mailchecker/webhook', 'secret' => self::SECRET, 'events' => ['check.completed']]];
        Http::fake([
            $ck1.'/*' => fn (Request $request) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $request->toPsrRequest())),
            $ck2.'/webhook' => Http::response($webhook),
        ]);

        $this->artisan('mailchecker:setup --skip-test')
            ->expectsOutputToContain($ck1)
            ->expectsOutputToContain("API token OK ({$ck2})")
            ->expectsOutputToContain('1 of 2 Mail Checker hosts failed')
            ->assertFailed();
    }

    public function test_setup_reports_a_bad_token(): void
    {
        Http::fake([self::API.'/webhook' => Http::response(['error' => ['message' => 'Unauthenticated. Send a valid bearer token.', 'status' => 401]], 401)]);

        $this->artisan('mailchecker:setup')->expectsOutputToContain('Unauthenticated')->assertFailed();
    }

    public function test_check_command(): void
    {
        Mailcheck::fake(['gone@example.com' => 'invalid']);

        $this->artisan('mailchecker:check gone@example.com')->expectsOutputToContain('invalid')->assertSuccessful();
        $this->assertTrue(TestEmailCheck::where('email', 'gone@example.com')->sole()->isInvalid());
    }

    public function test_sync_refreshes_unfinished_checks(): void
    {
        $check = TestEmailCheck::create(['email' => 'grey@example.com', 'reference' => '7b0f3c9e-1d2a-4f5b-8c6d-9e0f1a2b3c4d', 'state' => 'waiting', 'check_id' => '01kabc0000000000000000000a']);
        Http::fake([self::API.'/checks/01kabc0000000000000000000a' => Http::response(['data' => $this->apiCheck(['email' => 'grey@example.com'])])]);

        $this->artisan('mailchecker:sync')->expectsOutputToContain('Updated 1 check(s)')->assertSuccessful();

        $this->assertTrue($check->refresh()->isFinal());
        $this->assertCount(1, TestEmailCheck::$hooks);
    }
}
