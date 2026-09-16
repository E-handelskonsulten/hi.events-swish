<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Billing\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class OrganizerBillingLineDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly string $organizerName,
        public readonly int $soldOrders,
        public readonly int $soldTickets,
        public readonly int $refundedTickets,
        public readonly float $platformFeePerTicket,
        public readonly float $platformFeeTotal,
        public readonly bool $smsEnabled,
        public readonly int $smsSent,
        /** @var array<string, int> sent SMS per message type */
        public readonly array $smsSentByType,
        public readonly float $smsFeePerMessage,
        public readonly float $smsTotal,
        public readonly float $total,
        public readonly float $grossSales,
    ) {}
}
