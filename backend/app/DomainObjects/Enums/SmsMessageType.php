<?php

namespace HiEvents\DomainObjects\Enums;

enum SmsMessageType: string
{
    use BaseEnum;

    case TICKET = 'TICKET';
    case REFUND_NOTICE = 'REFUND_NOTICE';
}
