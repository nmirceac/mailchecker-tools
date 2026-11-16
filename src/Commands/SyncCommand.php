<?php

namespace MailcheckerTools\Commands;

use Illuminate\Console\Command;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Enums\CheckState;
use MailcheckerTools\Jobs\SubmitCheck;
use MailcheckerTools\MailcheckerTools;
use MailcheckerTools\Webhooks\WebhookProcessor;

/** Safety net for missed webhooks: schedule it, e.g. every 15 minutes. */
class SyncCommand extends Command
{
    protected $signature = 'mailchecker:sync {--days=3 : Only checks created in the last N days} {--limit=500}';

    protected $description = 'Re-submit stuck checks and refresh unfinished ones from the Mail Checker';

    public function handle(MailcheckClient $client, WebhookProcessor $processor): int
    {
        $class = MailcheckerTools::checkModel();
        $since = now()->subDays((int) $this->option('days'));
        $limit = (int) $this->option('limit');

        $requeued = 0;
        $class::query()->where('state', CheckState::Pending->value)
            ->where('created_at', '>=', $since)->where('created_at', '<', now()->subMinutes(10))
            ->limit($limit)->get()
            ->each(function ($check) use (&$requeued) {
                dispatch(MailcheckerTools::onQueue(new SubmitCheck($check::class, $check->getKey())));
                $requeued++;
            });

        $updated = 0;
        $errors = 0;
        $open = $class::query()->whereNotNull('check_id')
            ->where(fn ($q) => $q->whereIn('state', [CheckState::Queued->value, CheckState::Waiting->value])
                ->orWhere(fn ($q) => $q->where('state', CheckState::Completed->value)->where('final', false)))
            ->where('created_at', '>=', $since)
            ->limit($limit)->get();

        foreach ($open as $check) {
            try {
                if ($processor->check($client->getCheck((string) $check->check_id)) === 'updated') {
                    $updated++;
                }
            } catch (MailcheckApiException $e) {
                $errors++;
                $this->components->warn("#{$check->getKey()}: {$e->getMessage()}");
            }
        }

        $this->components->info("Updated {$updated} check(s), re-submitted {$requeued}.");

        return $open->isNotEmpty() && $errors === $open->count() ? self::FAILURE : self::SUCCESS;
    }
}
