<?php

namespace MailcheckerTools\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Facades\Mailcheck;
use MailcheckerTools\Rules\DeliverableEmail;
use MailcheckerTools\Tests\Fixtures\TestEmailCheck;
use MailcheckerTools\Tests\TestCase;

class ModelAndRuleTest extends TestCase
{
    public function test_request_submits_from_the_queue_and_the_webhook_completes_it(): void
    {
        $fake = Mailcheck::fake();

        $check = TestEmailCheck::request(' Someone@Example.com ');

        $check->refresh();
        $this->assertSame('someone@example.com', $check->email);
        $this->assertSame('queued', $check->state);
        $this->assertNotNull($check->check_id);
        $fake->assertChecked('someone@example.com');

        $this->postWebhook(['type' => 'check.completed', 'data' => $this->apiCheck(['id' => $check->check_id, 'email' => $check->email])])
            ->assertJsonPath('result', 'updated');
        $this->assertTrue($check->refresh()->isFinal());
    }

    public function test_check_now_stores_the_answer(): void
    {
        Mailcheck::fake(['gone@example.com' => 'invalid']);

        $check = TestEmailCheck::checkNow('gone@example.com');

        $this->assertTrue($check->isFinal());
        $this->assertTrue($check->isInvalid());
        $this->assertSame([['afterCheckCompleted', $check->id]], TestEmailCheck::$hooks);
    }

    public function test_the_flags_reach_the_model_through_the_fake(): void
    {
        Mailcheck::fake([
            'temp@mailinator.com' => ['result' => 'valid', 'disposable' => true],
            'jane@ymail.com' => ['catch_all' => true, 'free' => true],
            'info@gamil.com' => ['result' => 'invalid', 'role' => true, 'did_you_mean' => 'info@gmail.com'],
        ]);

        $disposable = TestEmailCheck::checkNow('temp@mailinator.com');
        $this->assertTrue($disposable->isValid());
        $this->assertTrue($disposable->isDisposable());
        $this->assertFalse($disposable->isCatchAll());
        $this->assertNull($disposable->didYouMean());

        $catchAll = TestEmailCheck::checkNow('jane@ymail.com');
        $this->assertTrue($catchAll->isUnknown(), 'a catch-all domain makes it unknown, as on the platform');
        $this->assertSame('accept_all', $catchAll->reason);
        $this->assertTrue($catchAll->isCatchAll());
        $this->assertTrue($catchAll->isFree());

        $typo = TestEmailCheck::checkNow('info@gamil.com');
        $this->assertTrue($typo->isRole());
        $this->assertSame('info@gmail.com', $typo->didYouMean());
    }

    public function test_request_many_uses_batches(): void
    {
        $fake = Mailcheck::fake();

        $checks = TestEmailCheck::requestMany(array_map(fn ($i) => "a{$i}@example.com", range(1, 120)));

        $this->assertCount(120, $checks);
        $fake->assertBatchChecked(100);
        $fake->assertBatchChecked(20);
        $this->assertSame(120, TestEmailCheck::where('state', 'queued')->count());
    }

    public function test_a_rejected_submission_is_marked_failed(): void
    {
        Http::fake([self::API.'/checks' => Http::response(['error' => ['message' => 'Invalid.', 'status' => 422, 'errors' => ['email' => ['invalid']]]], 422)]);

        $check = TestEmailCheck::request('nope@');

        $this->assertSame('failed', $check->refresh()->state);
    }

    public function test_the_rule_rejects_invalid_and_optionally_unknown_addresses(): void
    {
        Mailcheck::fake(['gone@example.com' => 'invalid', 'grey@example.com' => 'unknown']);

        $this->assertTrue(Validator::make(['e' => 'ok@example.com'], ['e' => new DeliverableEmail])->passes());
        $this->assertTrue(Validator::make(['e' => 'gone@example.com'], ['e' => new DeliverableEmail])->fails());
        $this->assertTrue(Validator::make(['e' => 'grey@example.com'], ['e' => new DeliverableEmail])->passes());
        $this->assertTrue(Validator::make(['e' => 'grey@example.com'], ['e' => new DeliverableEmail(allowUnknown: false)])->fails());
    }

    public function test_the_rule_can_refuse_disposable_addresses(): void
    {
        Mailcheck::fake(['temp@mailinator.com' => ['result' => 'valid', 'disposable' => true]]);

        $this->assertTrue(Validator::make(['e' => 'temp@mailinator.com'], ['e' => new DeliverableEmail])->passes(), 'allowed by default');
        $this->assertTrue(Validator::make(['e' => 'temp@mailinator.com'], ['e' => new DeliverableEmail(allowDisposable: false)])->fails(), 'from the cached answer too');
        $this->assertSame(
            ['The e is a disposable address. Please use a permanent one.'],
            Validator::make(['e' => 'temp@mailinator.com'], ['e' => new DeliverableEmail(allowDisposable: false)])->errors()->all(),
        );
    }

    public function test_the_rule_caches_and_fails_open(): void
    {
        $fake = Mailcheck::fake();
        Validator::make(['e' => 'ok@example.com'], ['e' => new DeliverableEmail])->passes();
        Validator::make(['e' => 'ok@example.com'], ['e' => new DeliverableEmail])->passes();
        $fake->assertChecked('ok@example.com', 1);

        app()->forgetInstance(MailcheckClient::class);
        Mailcheck::clearResolvedInstances();
        Http::fake([self::API.'/checks' => Http::response(['error' => ['message' => 'Server error.', 'status' => 503]], 503)]);

        $this->assertTrue(Validator::make(['e' => 'new@example.com'], ['e' => new DeliverableEmail])->passes());
        $this->assertTrue(Validator::make(['e' => 'new2@example.com'], ['e' => new DeliverableEmail(failOpen: false)])->fails());
    }
}
