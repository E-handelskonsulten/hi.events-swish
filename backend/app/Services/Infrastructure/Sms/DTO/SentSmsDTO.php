<?php

namespace HiEvents\Services\Infrastructure\Sms\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SentSmsDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $status,
        public readonly int $parts,
        public readonly bool $dryRun,
    ) {}
}
