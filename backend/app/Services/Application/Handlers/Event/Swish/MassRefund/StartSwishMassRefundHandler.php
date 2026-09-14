<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\Swish\MassRefund;

use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\Swish\SwishMassRefundConfirmationException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\Swish\MassRefund\DTO\StartSwishMassRefundDTO;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\SwishMassRefundRunService;
use Throwable;

class StartSwishMassRefundHandler
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SwishMassRefundRunService $runService,
    ) {}

    /**
     * @throws SwishMassRefundConfirmationException
     * @throws ResourceConflictException
     * @throws Throwable
     */
    public function handle(StartSwishMassRefundDTO $dto): SwishMassRefundRunDomainObject
    {
        $event = $this->eventRepository->findById($dto->eventId);

        if (trim($dto->confirmation) !== trim($event->getTitle())) {
            throw new SwishMassRefundConfirmationException(__('Type the event name exactly as shown to confirm the mass refund.'));
        }

        return $this->runService->start(
            event: $event,
            initiatedBy: $dto->initiatedBy,
            accountId: $dto->accountId,
            notifyBuyers: $dto->notifyBuyers,
            cancelOrders: $dto->cancelOrders,
        );
    }
}
