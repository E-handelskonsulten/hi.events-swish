<?php

declare(strict_types=1);

namespace HiEvents\Resources\Message;

use HiEvents\Services\Application\Handlers\Message\DTO\MessagePreviewDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MessagePreviewDTO
 */
class MessagePreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'email_recipients' => $this->emailRecipients,
            'sms_recipients' => $this->smsRecipients,
            'excluded_without_consent' => $this->excludedWithoutConsent,
            'excluded_without_phone' => $this->excludedWithoutPhone,
            'sms_available' => $this->smsAvailable,
            'sms_sender' => $this->smsSender,
            'sms_characters' => $this->smsCharacters,
            'sms_encoding' => $this->smsEncoding,
            'sms_parts' => $this->smsParts,
            'sms_single_part_limit' => $this->smsSinglePartLimit,
            'sms_opt_out_suffix_length' => $this->smsOptOutSuffixLength,
            'sms_cost_per_recipient' => $this->smsCostPerRecipient,
            'sms_total_cost' => $this->smsTotalCost,
            'currency' => $this->currency,
            'requires_confirmation' => $this->requiresConfirmation,
            'confirmation_word' => $this->confirmationWord,
        ];
    }
}
