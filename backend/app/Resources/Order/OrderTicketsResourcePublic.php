<?php

declare(strict_types=1);

namespace HiEvents\Resources\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Resources\Attendee\AttendeeResourcePublic;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin OrderDomainObject
 */
class OrderTicketsResourcePublic extends BaseResource
{
    public function toArray(Request $request): array
    {
        $isValid = $this->getStatus() === OrderStatus::COMPLETED->name && ! $this->isFullyRefunded();

        return [
            'short_id' => $this->getShortId(),
            'public_id' => $this->getPublicId(),
            'event_id' => $this->getEventId(),
            'first_name' => $this->getFirstName(),
            /** @var 'COMPLETED'|'CANCELLED'|'AWAITING_OFFLINE_PAYMENT' */
            'status' => $this->getStatus(),
            'is_fully_refunded' => $this->isFullyRefunded(),
            'is_valid' => $isValid,
            'attendees' => $this->getAttendees()
                ->sortBy(fn (AttendeeDomainObject $attendee) => $attendee->getId())
                ->values()
                ->map(fn (AttendeeDomainObject $attendee) => new AttendeeResourcePublic($attendee, $isValid)),
        ];
    }
}
