<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Helper\Url;
use Illuminate\Support\Collection;

class SmsMessageBuilder
{
    /**
     * @param  Collection<int, AttendeeDomainObject>  $attendees
     */
    public function build(SmsMessageType $type, OrderDomainObject $order, EventDomainObject $event, Collection $attendees): string
    {
        $locale = $order->getLocale();
        $firstName = trim((string) $order->getFirstName());

        $greeting = $firstName !== ''
            ? __('Hi :name!', ['name' => $firstName], $locale)
            : __('Hi!', [], $locale);

        $body = match ($type) {
            SmsMessageType::TICKET => $attendees->count() > 1
                ? __('Your tickets for :event: :url', [
                    'event' => $event->getTitle(),
                    'url' => sprintf(Url::getFrontEndUrlFromConfig(Url::ORDER_TICKETS), $order->getShortId()),
                ], $locale)
                : __('Your ticket for :event: :url', [
                    'event' => $event->getTitle(),
                    'url' => $this->singleTicketUrl($order, $event, $attendees->first()),
                ], $locale),
            SmsMessageType::REFUND_NOTICE => __('Your order for :event has been refunded and the ticket is no longer valid.', [
                'event' => $event->getTitle(),
            ], $locale),
        };

        return $greeting.' '.$body;
    }

    private function singleTicketUrl(OrderDomainObject $order, EventDomainObject $event, ?AttendeeDomainObject $attendee): string
    {
        if ($attendee !== null) {
            return sprintf(Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET), $event->getId(), $attendee->getShortId());
        }

        return sprintf(Url::getFrontEndUrlFromConfig(Url::ORDER_TICKETS), $order->getShortId());
    }
}
