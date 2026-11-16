<?php

namespace MailcheckerTools\Tests\Feature;

use Illuminate\Support\Facades\Event;
use MailcheckerTools\Events\EmailCheckCompleted;
use MailcheckerTools\Tests\Fixtures\TestEmailCheck;
use MailcheckerTools\Tests\TestCase;

class WebhookTest extends TestCase
{
    private function saved(): TestEmailCheck
    {
        return TestEmailCheck::create(['email' => 'someone@example.com', 'reference' => '7b0f3c9e-1d2a-4f5b-8c6d-9e0f1a2b3c4d', 'state' => 'queued', 'check_id' => '01kabc0000000000000000000a']);
    }

    public function test_unsigned_badly_signed_and_stale_events_are_rejected(): void
    {
        $event = ['type' => 'webhook.test', 'data' => ['testing' => true]];

        $this->postWebhook($event, null)->assertUnauthorized()->assertJsonPath('error.message', 'Missing signature');
        $this->postWebhook($event, 'whsec_wrong')->assertUnauthorized()->assertJsonPath('error.message', 'Invalid signature');
        $this->postWebhook($event, timestamp: time() - 3600)->assertUnauthorized()->assertJsonPath('error.message', 'Stale timestamp');
        $this->postWebhook($event)->assertOk()->assertJson(['ok' => true, 'result' => 'tested']);
    }

    public function test_without_a_secret_nothing_is_accepted(): void
    {
        config(['mailchecker.webhook.secret' => null]);

        $this->postWebhook(['type' => 'webhook.test'], 'anything')->assertUnauthorized();
    }

    public function test_a_completed_check_updates_the_row_runs_the_hook_and_fires_the_event(): void
    {
        Event::fake([EmailCheckCompleted::class]);
        $check = $this->saved();

        $this->postWebhook(['type' => 'check.completed', 'data' => $this->apiCheck(['result' => 'invalid', 'reason' => 'rejected', 'mode' => 'async'])])
            ->assertOk()->assertJsonPath('result', 'updated');

        $check->refresh();
        $this->assertTrue($check->isFinal());
        $this->assertTrue($check->isInvalid());
        $this->assertSame('rejected', $check->reason);
        $this->assertSame([['afterCheckCompleted', $check->id]], TestEmailCheck::$hooks);
        Event::assertDispatched(EmailCheckCompleted::class, fn (EmailCheckCompleted $e) => $e->check->is($check));
    }

    public function test_events_are_matched_by_reference_and_unknown_checks_are_recorded(): void
    {
        $check = TestEmailCheck::create(['email' => 'someone@example.com', 'reference' => '7b0f3c9e-1d2a-4f5b-8c6d-9e0f1a2b3c4d', 'state' => 'pending']);

        $this->postWebhook(['type' => 'check.completed', 'data' => $this->apiCheck(['reference' => $check->reference])])->assertJsonPath('result', 'updated');
        $this->assertSame('01kabc0000000000000000000a', $check->refresh()->check_id);

        $this->postWebhook(['type' => 'check.completed', 'data' => $this->apiCheck(['id' => '01kabc0000000000000000000b', 'email' => 'Other@Example.com'])])
            ->assertJsonPath('result', 'created');
        $this->assertTrue(TestEmailCheck::where('email', 'other@example.com')->sole()->isValid());
    }

    public function test_an_event_is_processed_once(): void
    {
        $this->saved();
        $event = ['id' => '01kevent00000000000000000a', 'type' => 'check.completed', 'data' => $this->apiCheck()];

        $this->postWebhook($event)->assertJsonPath('result', 'updated');
        $this->postWebhook($event)->assertJsonPath('result', 'duplicate');
        $this->assertCount(1, TestEmailCheck::$hooks);
    }

    public function test_a_final_result_is_never_replaced_by_a_non_final_one(): void
    {
        $check = $this->saved();
        $this->postWebhook(['type' => 'check.completed', 'data' => $this->apiCheck()]);
        $this->postWebhook(['type' => 'check.completed', 'data' => $this->apiCheck(['state' => 'waiting', 'result' => 'unknown', 'final' => false])])
            ->assertJsonPath('result', 'unchanged');

        $this->assertTrue($check->refresh()->isValid());
    }
}
