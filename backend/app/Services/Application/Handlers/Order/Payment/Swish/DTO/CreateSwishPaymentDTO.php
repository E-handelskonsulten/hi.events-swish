<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Payment\Swish\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SwishCheckoutFlow;

class CreateSwishPaymentDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $orderShortId,
        public readonly SwishCheckoutFlow $flow,
        public readonly ?string $payerAlias = null,
    ) {}
}
