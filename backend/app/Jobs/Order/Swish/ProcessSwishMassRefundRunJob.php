<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Order\Swish;

use HiEvents\Services\Domain\Payment\Swish\MassRefund\SwishMassRefundProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSwishMassRefundRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('swish-mass-refund:'.$this->runId))->dontRelease()->expireAfter(600),
        ];
    }

    public function handle(SwishMassRefundProcessor $processor): void
    {
        $processor->processBatch($this->runId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Swish mass refund batch failed; the stalled-run monitor will resume it', [
            'run_id' => $this->runId,
            'error' => $exception->getMessage(),
        ]);
    }
}
