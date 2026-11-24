<?php

namespace MailcheckerTools\Commands;

use Illuminate\Console\Command;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Facades\Mailcheck;

class ForexCommand extends Command
{
    protected $signature = 'mailchecker:forex
        {from : Currency code, e.g. ZAR}
        {to : Currency code, e.g. USD}
        {amount=1 : Amount in the first currency}
        {--date= : The day (Y-m-d, UTC); default today}';

    protected $description = 'Show an exchange rate, or convert an amount';

    public function handle(): int
    {
        $amount = $this->argument('amount');
        if (! is_numeric($amount)) {
            $this->components->error('The amount must be a number.');

            return self::INVALID;
        }

        try {
            $rate = Mailcheck::exchangeRate(
                (string) $this->argument('from'),
                (string) $this->argument('to'),
                is_string($this->option('date')) ? $this->option('date') : null,
            );
        } catch (MailcheckApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $converted = (float) $amount * $rate['rate'];
        $this->line("  {$amount} {$rate['from']} = ".rtrim(rtrim(number_format($converted, 10, '.', ''), '0'), '.')." {$rate['to']} on {$rate['date']}");

        $source = match (true) {
            $rate['fetched_at'] === null => 'same currency',
            $rate['stale'] => "stale (today's rates unavailable), fetched {$rate['fetched_at']}",
            $rate['cached'] => "stored by the Mail Checker, fetched {$rate['fetched_at']}",
            default => 'fetched now',
        };
        $this->line("  <fg=gray>{$source}</>");

        return self::SUCCESS;
    }
}
