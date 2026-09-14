<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SwishMassRefundTicketTypeDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $name,
        public readonly int $quantity,
        public readonly int $order_count,
        public readonly float $amount,
    ) {}
}
