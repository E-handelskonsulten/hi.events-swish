<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Billing;

use Carbon\CarbonImmutable;
use HiEvents\Services\Domain\Billing\MonthlyBillingSummaryDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMonthlyBillingSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $periodStart,
        public readonly bool $force = false,
        public readonly ?string $previewRecipient = null,
    ) {}

    public function handle(MonthlyBillingSummaryDispatchService $service): void
    {
        $service->send(CarbonImmutable::parse($this->periodStart), $this->force, $this->previewRecipient);
    }
}
