<?php

namespace MailcheckerTools\Commands;

use Illuminate\Console\Command;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\MailcheckerTools;

class CheckCommand extends Command
{
    protected $signature = 'mailchecker:check {email} {--async : Queue it; the result arrives by webhook} {--force : Probe even if checked recently}';

    protected $description = 'Check an email address (and store it in the checks table)';

    public function handle(): int
    {
        $class = MailcheckerTools::checkModel();
        $email = (string) $this->argument('email');

        if ($this->option('async')) {
            $check = $class::request($email);
            $this->components->info("Queued check #{$check->getKey()} (reference {$check->reference}); the result arrives by webhook.");

            return self::SUCCESS;
        }

        try {
            $check = $class::checkNow($email, force: (bool) $this->option('force'));
        } catch (MailcheckApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Address', $check->email);
        $this->components->twoColumnDetail('Result', (string) ($check->result ?? '—'));
        $this->components->twoColumnDetail('Reason', (string) ($check->reason ?? '—'));
        $this->components->twoColumnDetail('Final', $check->final ? 'yes' : 'no, rechecks running (webhook follows)');
        $this->components->twoColumnDetail('Cached', $check->cached ? 'yes' : 'no');

        return self::SUCCESS;
    }
}
