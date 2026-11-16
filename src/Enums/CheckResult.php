<?php

namespace MailcheckerTools\Enums;

enum CheckResult: string
{
    /** The mailbox exists (the mail server accepted it). */
    case Valid = 'valid';
    /** The mailbox doesn't exist, or the domain has no mail server. */
    case Invalid = 'invalid';
    /** No definite answer (greylisting, timeouts, blocks). Usually safe to accept. */
    case Unknown = 'unknown';
}
