<?php

namespace MailcheckerTools\Commands;

use Illuminate\Console\Command;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Facades\Mailcheck;

class GeoipCommand extends Command
{
    protected $signature = 'mailchecker:geoip {ip : IPv4 or IPv6 address}';

    protected $description = 'Look up the location of an IP address';

    public function handle(): int
    {
        try {
            $answer = Mailcheck::geoip((string) $this->argument('ip'));
        } catch (MailcheckApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($answer['data'] as $key => $value) {
            $this->components->twoColumnDetail((string) $key, is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : (string) json_encode($value)));
        }

        $source = match (true) {
            $answer['fetched_at'] === null => 'private or reserved address (no lookup)',
            $answer['stale'] => "stale (geoiplookup.io unreachable), fetched {$answer['fetched_at']}",
            $answer['cached'] => "cached by the Mail Checker, fetched {$answer['fetched_at']}",
            default => 'fetched now',
        };
        $this->newLine();
        $this->line("  <fg=gray>{$source}</>");

        return self::SUCCESS;
    }
}
