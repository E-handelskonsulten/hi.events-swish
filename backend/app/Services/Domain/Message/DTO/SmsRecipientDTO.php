<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Message\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsRecipientDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $phone,
    ) {}
}
