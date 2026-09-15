<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\Services\Domain\Sms\TicketSmsScheduleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDueSmsMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(TicketSmsScheduleService $service): void
    {
        $service->dispatchDue();
    }
}
