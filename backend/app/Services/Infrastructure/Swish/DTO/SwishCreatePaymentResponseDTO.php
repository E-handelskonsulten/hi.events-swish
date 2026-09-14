<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Swish\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SwishCreatePaymentResponseDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $location,
        public readonly ?string $paymentRequestToken,
    ) {}
}
