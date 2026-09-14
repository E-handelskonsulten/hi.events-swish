<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Order\Swish;

use HiEvents\Services\Application\Handlers\Order\Payment\Swish\SwishPaymentCallbackHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSwishPaymentCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [5, 30];

    public function __construct(private readonly array $payload) {}

    /**
     * @throws Throwable
     */
    public function handle(SwishPaymentCallbackHandler $handler): void
    {
        $handler->handle($this->payload);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Swish payment callback processing failed permanently', [
            'instruction_uuid' => $this->payload['id'] ?? null,
            'remote_status' => $this->payload['status'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
