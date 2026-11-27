<?php

namespace MailcheckerTools\Commands;

use Illuminate\Console\Command;
use MailcheckerTools\Client\MailcheckApiException;
use MailcheckerTools\Client\MailcheckClient;

class SetupCommand extends Command
{
    protected $signature = 'mailchecker:setup
        {--url= : Webhook URL to register (defaults to this app\'s webhook route)}
        {--write-env : Write MAILCHECKER_WEBHOOK_SECRET into .env}
        {--skip-test : Do not send a test event}';

    protected $description = 'Check the API token (on every Mail Checker host), register the webhook URL and test it';

    public function handle(MailcheckClient $client): int
    {
        try {
            $failedHosts = $this->checkEachHost($client);

            $current = $client->webhook();
            if (count($client->urls()) === 1) {
                $this->components->info('API token OK ('.$client->urls()[0].')');
            }

            $url = (string) ($this->option('url') ?: route((string) config('mailchecker.webhook.route.name')));
            if ($current['url'] !== $url) {
                $current = $client->updateWebhook($url);
                $this->components->info("Webhook URL set to {$url}");
            } else {
                $this->components->info("Webhook URL already {$url}");
            }

            $secret = $current['secret'];
            if (config('mailchecker.webhook.secret') !== $secret) {
                if ($this->option('write-env') && $this->writeEnv('MAILCHECKER_WEBHOOK_SECRET', $secret)) {
                    config(['mailchecker.webhook.secret' => $secret]);
                    $this->components->info('MAILCHECKER_WEBHOOK_SECRET written to .env');
                    if (app()->configurationIsCached()) {
                        $this->components->warn('The configuration is cached: run `php artisan config:cache` so the app uses the new secret.');
                    }
                } else {
                    $this->components->warn('Add the signing secret to your .env (or re-run with --write-env):');
                    $this->line("  MAILCHECKER_WEBHOOK_SECRET={$secret}");
                }
            } else {
                $this->components->info('Webhook signing secret matches .env');
            }

            if (! $this->option('skip-test')) {
                $test = $client->testWebhook();
                if ($test['ok']) {
                    $this->components->info("Test event delivered (HTTP {$test['status']})");
                } else {
                    $this->components->error('Test event failed: '.($test['error'] ?? 'unknown error').'. Is this URL reachable from the Mail Checker?');

                    return self::FAILURE;
                }
            }
        } catch (MailcheckApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($failedHosts > 0) {
            $this->components->warn("{$failedHosts} of ".count($client->urls()).' Mail Checker hosts failed; until they answer, calls go to the others.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * With several Mail Checker hosts (workers), asks each one on its own, so a worker that can't be
     * reached shows up here rather than only when the others fail too.
     *
     * @return int how many failed
     */
    private function checkEachHost(MailcheckClient $client): int
    {
        $urls = $client->urls();
        if (count($urls) < 2) {
            return 0;
        }

        $failed = 0;
        foreach ($urls as $url) {
            try {
                $client->forHost($url)->webhook();
                $this->components->info("API token OK ({$url})");
            } catch (MailcheckApiException $e) {
                $failed++;
                $this->components->error("{$url}: {$e->getMessage()}");
            }
        }

        return $failed;
    }

    private function writeEnv(string $key, string $value): bool
    {
        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*/m';
        $contents = preg_match($pattern, $contents)
            ? (string) preg_replace_callback($pattern, fn () => $line, $contents)
            : rtrim($contents).PHP_EOL.$line.PHP_EOL;

        return file_put_contents($path, $contents) !== false;
    }
}
