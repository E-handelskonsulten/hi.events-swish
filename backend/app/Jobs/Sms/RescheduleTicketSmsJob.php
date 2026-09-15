<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Sms;

use HiEvents\Services\Domain\Sms\TicketSmsScheduleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RescheduleTicketSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $where
     */
    public function __construct(public readonly array $where)
    {
        $this->afterCommit();
    }

    public static function forEvent(int $eventId): self
    {
        return new self(['event_id' => $eventId]);
    }

    public static function forOrganizer(int $organizerId): self
    {
        return new self(['organizer_id' => $organizerId]);
    }

    public function handle(TicketSmsScheduleService $service): void
    {
        $service->reschedule($this->where);
    }
}
