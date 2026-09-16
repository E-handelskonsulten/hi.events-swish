<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Message\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Support\Collection;

class MessageRecipientsDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, SmsRecipientDTO>  $smsRecipients
     * @param  string[]  $consentedEmails
     */
    public function __construct(
        public readonly int $emailRecipients,
        public readonly Collection $smsRecipients,
        public readonly int $excludedWithoutConsent,
        public readonly int $excludedWithoutPhone,
        public readonly array $consentedEmails,
    ) {}
}
