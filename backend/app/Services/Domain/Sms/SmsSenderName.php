<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

class SmsSenderName
{
    public const PATTERN = '/^[A-Za-z][A-Za-z0-9]{2,10}$/';
}
