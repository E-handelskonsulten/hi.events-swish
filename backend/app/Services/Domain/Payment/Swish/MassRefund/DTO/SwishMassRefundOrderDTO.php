<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SwishMassRefundOrderDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $order_id,
        public readonly string $public_id,
        public readonly ?string $buyer_name,
        public readonly ?string $buyer_email,
        public readonly float $amount,
        public readonly ?string $reason = null,
        public readonly ?string $reason_label = null,
    ) {}
}
