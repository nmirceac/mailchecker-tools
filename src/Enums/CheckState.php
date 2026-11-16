<?php

namespace MailcheckerTools\Enums;

/** Where a check is. pending and failed are local; the others come from the platform. */
enum CheckState: string
{
    /** Saved here, not yet accepted by the platform (the SubmitCheck job is queued). */
    case Pending = 'pending';
    /** Accepted; the platform hasn't probed it yet. */
    case Queued = 'queued';
    /** Probed, the result is unknown (e.g. greylisted) and the platform is rechecking. */
    case Waiting = 'waiting';
    /** The result is final. */
    case Completed = 'completed';
    /** The platform refused the request (e.g. not a valid address) or could not be reached for days. */
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
