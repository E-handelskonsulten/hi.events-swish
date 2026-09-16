<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Event;

use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Domain\Message\SendEventSmsMessagesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSmsMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly SendMessageDTO $messageData) {}

    public function handle(SendEventSmsMessagesService $service): void
    {
        $service->send($this->messageData);
    }
}
