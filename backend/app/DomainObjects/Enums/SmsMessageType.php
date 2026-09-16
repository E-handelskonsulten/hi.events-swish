<?php

namespace HiEvents\DomainObjects\Enums;

enum SmsMessageType: string
{
    use BaseEnum;

    case TICKET = 'TICKET';
    case REFUND_NOTICE = 'REFUND_NOTICE';
    case SERVICE = 'SERVICE';
    case MARKETING = 'MARKETING';

    public static function forPurpose(MessagePurpose $purpose): self
    {
        return $purpose === MessagePurpose::MARKETING ? self::MARKETING : self::SERVICE;
    }
}
