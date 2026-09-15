<?php

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SmsMessageStatus: string
{
    use BaseEnum;

    case SCHEDULED = 'SCHEDULED';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
