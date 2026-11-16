<?php

namespace MailcheckerTools\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MailcheckerTools\Models\EmailCheck;

/** A check got its final result (by webhook, a sync call or mailchecker:sync). */
class EmailCheckCompleted
{
    use Dispatchable;

    public function __construct(public EmailCheck $check) {}
}
