<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Helper\Url;

class SmsMessageBuilder
{
    public function build(SmsMessageType $type, OrderDomainObject $order, EventDomainObject $event, ?AttendeeDomainObject $attendee = null): string
    {
        $locale = $order->getLocale();
        $firstName = trim((string) $order->getFirstName());

        $greeting = $firstName !== ''
            ? __('Hi :name!', ['name' => $firstName], $locale)
            : __('Hi!', [], $locale);

        $body = match ($type) {
            SmsMessageType::TICKET => __('Your ticket for :event: :url', [
                'event' => $event->getTitle(),
                'url' => $this->ticketUrl($order, $event, $attendee),
            ], $locale),
            SmsMessageType::REFUND_NOTICE => __('Your order for :event has been refunded and the ticket is no longer valid.', [
                'event' => $event->getTitle(),
            ], $locale),
        };

        return $greeting.' '.$body;
    }

    private function ticketUrl(OrderDomainObject $order, EventDomainObject $event, ?AttendeeDomainObject $attendee): string
    {
        if ($attendee !== null) {
            return sprintf(Url::getFrontEndUrlFromConfig(Url::ATTENDEE_TICKET), $event->getId(), $attendee->getShortId());
        }

        return sprintf(Url::getFrontEndUrlFromConfig(Url::ORDER_SUMMARY), $event->getId(), $order->getShortId());
    }
}
