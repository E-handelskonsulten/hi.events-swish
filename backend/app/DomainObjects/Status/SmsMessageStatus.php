<?php

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SmsMessageStatus: string
{
    use BaseEnum;

    case SENT = 'SENT';
    case FAILED = 'FAILED';
}
