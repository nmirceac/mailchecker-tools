<?php

namespace MailcheckerTools;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MailcheckerTools\Client\MailcheckClient;
use MailcheckerTools\Commands\CheckCommand;
use MailcheckerTools\Commands\SetupCommand;
use MailcheckerTools\Commands\SyncCommand;
use MailcheckerTools\Http\Controllers\WebhookController;
use MailcheckerTools\Http\Middleware\VerifyWebhookSignature;

class MailcheckerToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailchecker.php', 'mailchecker');

        $this->app->singleton(MailcheckClient::class, fn () => new MailcheckClient(
            config('mailchecker.api.url'),
            config('mailchecker.api.token'),
            (int) config('mailchecker.api.timeout', 60),
            (int) config('mailchecker.api.retries', 2),
        ));
    }

    public function boot(): void
    {
        if (config('mailchecker.webhook.route.enabled', true)) {
            Route::post((string) config('mailchecker.webhook.route.path'), WebhookController::class)
                ->middleware([...(array) config('mailchecker.webhook.route.middleware', []), VerifyWebhookSignature::class])
                ->name((string) config('mailchecker.webhook.route.name'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SetupCommand::class, CheckCommand::class, SyncCommand::class]);

            $this->publishes([__DIR__.'/../config/mailchecker.php' => config_path('mailchecker.php')], 'mailchecker-tools-config');
            $this->publishes([__DIR__.'/../stubs/EmailCheck.stub' => app_path('Models/EmailCheck.php')], 'mailchecker-tools-models');
            $this->publishes([
                __DIR__.'/../database/migrations/create_mailchecker_checks_table.php.stub' => $this->migrationPath('create_mailchecker_checks_table'),
            ], 'mailchecker-tools-migrations');
        }
    }

    /** Publishing twice overwrites the first copy instead of adding a second migration. */
    private function migrationPath(string $name): string
    {
        $existing = glob(database_path("migrations/*_{$name}.php")) ?: [];

        return $existing[0] ?? database_path('migrations/'.date('Y_m_d_His')."_{$name}.php");
    }
}
