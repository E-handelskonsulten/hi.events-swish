<?php

namespace HiEvents\Resources\Message;

use HiEvents\DomainObjects\MessageDomainObject;
use HiEvents\Resources\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MessageDomainObject
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'event_id' => $this->getEventId(),
            'subject' => $this->getSubject(),
            'message' => $this->getMessage(),
            'type' => $this->getType(),
            /** @var 'EMAIL'|'SMS'|'BOTH' */
            'channel' => $this->getChannel(),
            /** @var 'SERVICE'|'MARKETING' */
            'purpose' => $this->getPurpose(),
            'sms_body' => $this->getSmsBody(),
            'recipient_count' => $this->getRecipientCount(),
            'sms_cost' => $this->getSmsCost() === null ? null : (float) $this->getSmsCost(),
            'sms_currency' => $this->getSmsCost() === null ? null : (string) config('billing.currency'),
            'attendee_ids' => $this->getAttendeeIds(),
            'order_id' => $this->getOrderId(),
            'product_ids' => $this->getProductIds(),
            'sent_at' => $this->getSentAt(),
            'status' => $this->getStatus(),
            'scheduled_at' => $this->getScheduledAt(),
            'message_preview' => $this->getMessagePreview(),
            $this->mergeWhen(! is_null($this->getSentByUser()), fn () => [
                'sent_by_user' => new UserResource($this->getSentByUser()),
            ]),
        ];
    }
}
