<?php

declare(strict_types=1);

namespace HiEvents\Listeners\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Sms\SendOrderSmsJob;

class SendTicketSmsListener
{
    public function handle(OrderStatusChangedEvent $changedEvent): void
    {
        if (! $changedEvent->order->isOrderCompleted()) {
            return;
        }

        dispatch(new SendOrderSmsJob($changedEvent->order->getId(), SmsMessageType::TICKET));
    }
}
