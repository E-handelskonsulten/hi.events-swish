<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\Swish\MassRefund;

use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO\SwishMassRefundPreviewDTO;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\SwishMassRefundPreflightService;

class PreviewSwishMassRefundHandler
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SwishMassRefundPreflightService $preflightService,
    ) {}

    public function handle(int $eventId): SwishMassRefundPreviewDTO
    {
        return $this->preflightService->preview($this->eventRepository->findById($eventId));
    }
}
