<?php

declare(strict_types=1);

namespace HiEvents\Listeners\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\Jobs\Sms\SendOrderSmsJob;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;

class SendRefundSmsListener
{
    public function handle(OrderEvent $event): void
    {
        if ($event->type !== DomainEventType::ORDER_REFUNDED) {
            return;
        }

        dispatch(new SendOrderSmsJob($event->orderId, SmsMessageType::REFUND_NOTICE));
    }
}
