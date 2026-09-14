<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum SwishMassRefundManualReason: string
{
    use BaseEnum;

    case PAYMENT_OLDER_THAN_12_MONTHS = 'payment_older_than_12_months';
    case PREVIOUS_REFUND_FAILED = 'previous_refund_failed';
    case REFUND_PENDING = 'refund_pending';
    case NO_SWISH_PAYMENT_REFERENCE = 'no_swish_payment_reference';

    public function label(): string
    {
        return match ($this) {
            self::PAYMENT_OLDER_THAN_12_MONTHS => __('The Swish payment is older than 12 months and cannot be refunded through Swish.'),
            self::PREVIOUS_REFUND_FAILED => __('A previous refund attempt for this order failed.'),
            self::REFUND_PENDING => __('A refund is already pending for this order.'),
            self::NO_SWISH_PAYMENT_REFERENCE => __('No completed Swish payment reference is stored for this order.'),
        };
    }
}
