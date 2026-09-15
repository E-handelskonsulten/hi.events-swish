<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\Exceptions\Sms\SmsDeliveryException;
use HiEvents\Services\Domain\Sms\OrderSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class SendOrderSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var int[]
     */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $orderId,
        public readonly SmsMessageType $type,
    ) {
        $this->afterCommit();
    }

    public function handle(OrderSmsService $service, LoggerInterface $logger): void
    {
        try {
            $service->send($this->orderId, $this->type);
        } catch (SmsDeliveryException $exception) {
            $logger->warning('SMS delivery failed', [
                'order_id' => $this->orderId,
                'type' => $this->type->name,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? end($this->backoff));

                return;
            }

            $this->fail($exception);
        }
    }
}
