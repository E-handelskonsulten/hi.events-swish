<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SwishMassRefundPreviewDTO extends BaseDataObject
{
    /**
     * @param  SwishMassRefundOrderDTO[]  $refundable_orders
     * @param  SwishMassRefundOrderDTO[]  $manual_orders
     * @param  SwishMassRefundTicketTypeDTO[]  $ticket_types
     */
    public function __construct(
        public readonly int $event_id,
        public readonly string $event_title,
        public readonly string $currency,
        public readonly int $refundable_count,
        public readonly float $total_amount,
        public readonly int $manual_count,
        public readonly float $manual_amount,
        public readonly array $ticket_types,
        public readonly array $refundable_orders,
        public readonly array $manual_orders,
        public readonly ?int $active_run_id,
    ) {}
}
