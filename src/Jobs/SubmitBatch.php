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

/** Submits up to 100 pending checks in one batch call; references make a retry safe. */
class SubmitBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /**
     * @param  class-string<EmailCheck>  $modelClass
     * @param  list<int|string>  $checkIds
     */
    public function __construct(public string $modelClass, public array $checkIds) {}

    public function handle(MailcheckClient $client): void
    {
        $checks = $this->modelClass::query()->whereKey($this->checkIds)->where('state', CheckState::Pending->value)->get()->values();
        if ($checks->isEmpty()) {
            return;
        }

        try {
            $results = $client->checkBatch($checks->map(fn (EmailCheck $c) => ['email' => $c->email, 'reference' => $c->reference])->all());
        } catch (MailcheckApiException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            $checks->each(fn (EmailCheck $c) => $c->markFailed($e->getMessage()));

            return;
        }

        foreach ($results as $row) {
            $check = $checks[$row['index']] ?? null;
            if ($check === null) {
                continue;
            }
            $row['check'] !== null
                ? $check->applyApiCheck($row['check'])
                : $check->markFailed(json_encode($row['errors']) ?: 'Rejected by the platform');
        }
    }

    public function failed(Throwable $e): void
    {
        $this->modelClass::query()->whereKey($this->checkIds)->where('state', CheckState::Pending->value)->get()
            ->each(fn (EmailCheck $c) => $c->markFailed($e->getMessage()));
    }
}
