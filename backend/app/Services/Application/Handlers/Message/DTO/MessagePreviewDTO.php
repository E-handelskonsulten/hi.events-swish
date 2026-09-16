<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Message\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class MessagePreviewDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $emailRecipients,
        public readonly int $smsRecipients,
        public readonly int $excludedWithoutConsent,
        public readonly int $excludedWithoutPhone,
        public readonly bool $smsAvailable,
        public readonly string $smsSender,
        public readonly int $smsCharacters,
        public readonly string $smsEncoding,
        public readonly int $smsParts,
        public readonly int $smsSinglePartLimit,
        public readonly int $smsOptOutSuffixLength,
        public readonly float $smsCostPerRecipient,
        public readonly float $smsTotalCost,
        public readonly string $currency,
        public readonly bool $requiresConfirmation,
        public readonly string $confirmationWord,
    ) {}
}
