<?php

namespace MailcheckerTools\Tests\Fixtures;

use MailcheckerTools\Models\EmailCheck;

class TestEmailCheck extends EmailCheck
{
    /** @var list<array{0: string, 1: int|string}> */
    public static array $hooks = [];

    public function afterCheckCompleted(): void
    {
        self::$hooks[] = ['afterCheckCompleted', $this->getKey()];
    }
}
