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

    protected $description = 'Check the API token, register the webhook URL and test it';

    public function handle(MailcheckClient $client): int
    {
        try {
            $current = $client->webhook();
            $this->components->info('API token OK ('.config('mailchecker.api.url').')');

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

        return self::SUCCESS;
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
