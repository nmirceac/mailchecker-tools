<?php

namespace MailcheckerTools;

use Illuminate\Support\Carbon;
use LogicException;
use MailcheckerTools\Models\EmailCheck;

final class MailcheckerTools
{
    /** @return class-string<EmailCheck> */
    public static function checkModel(): string
    {
        $class = (string) config('mailchecker.models.check', EmailCheck::class);
        if (! class_exists($class) || ! is_a($class, EmailCheck::class, true)) {
            throw new LogicException("mailchecker.models.check ({$class}) must be a class extending ".EmailCheck::class
                .'. Publish it with `php artisan vendor:publish --tag=mailchecker-tools-models`, or set MAILCHECKER_CHECK_MODEL.');
        }

        return $class;
    }

    /**
     * Apply the configured queue connection/name to a job.
     *
     * @template T of object
     *
     * @param  T  $job
     * @return T
     */
    public static function onQueue(object $job): object
    {
        if (method_exists($job, 'onConnection') && config('mailchecker.queue.connection')) {
            $job->onConnection(config('mailchecker.queue.connection'));
        }
        if (method_exists($job, 'onQueue') && config('mailchecker.queue.name')) {
            $job->onQueue(config('mailchecker.queue.name'));
        }

        return $job;
    }

    /** A platform timestamp (UTC, "…Z") in the app's timezone, the one now() and Eloquent use. */
    public static function localTime(?string $value): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->setTimezone(date_default_timezone_get());
    }
}
