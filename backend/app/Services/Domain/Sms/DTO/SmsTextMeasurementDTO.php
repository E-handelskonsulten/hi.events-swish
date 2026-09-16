<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SmsTextMeasurementDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $characters,
        public readonly string $encoding,
        public readonly int $parts,
        public readonly int $singlePartLimit,
    ) {}
}
