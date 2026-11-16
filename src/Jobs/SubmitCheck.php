<?php

namespace MailcheckerTools\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Enums\CheckState;
use MailcheckerTools\Models\EmailCheck;
use Throwable;

/** Submits a pending check (async); its reference makes a retry safe. */
class SubmitCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /** @param  class-string<EmailCheck>  $modelClass */
    public function __construct(public string $modelClass, public int|string $checkId) {}

    public function handle(MailcheckClient $client): void
    {
        $check = $this->modelClass::query()->find($this->checkId);
        if ($check === null || $check->state !== CheckState::Pending->value) {
            return;
        }

        try {
            $check->applyApiCheck($client->check($check->email, true, $check->reference));
        } catch (MailcheckApiException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            $check->markFailed($e->getMessage());
        }
    }

    public function failed(Throwable $e): void
    {
        $check = $this->modelClass::query()->find($this->checkId);
        if ($check !== null && $check->state === CheckState::Pending->value) {
            $check->markFailed($e->getMessage());
        }
    }
}
