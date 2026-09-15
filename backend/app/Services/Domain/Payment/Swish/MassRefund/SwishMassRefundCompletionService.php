<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish\MassRefund;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SwishMassRefundItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishMassRefundRunDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\SwishMassRefundItemStatus;
use HiEvents\DomainObjects\Status\SwishMassRefundRunStatus;
use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\Mail\Swish\SwishMassRefundCompletedMail;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishMassRefundItemsRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;
use Illuminate\Contracts\Mail\Mailer;
use Psr\Log\LoggerInterface;

class SwishMassRefundCompletionService
{
    public function __construct(
        private readonly SwishMassRefundRunsRepositoryInterface $runsRepository,
        private readonly SwishMassRefundItemsRepositoryInterface $itemsRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function complete(int $runId): void
    {
        /** @var SwishMassRefundRunDomainObject $run */
        $run = $this->runsRepository->updateFromArray($runId, [
            SwishMassRefundRunDomainObjectAbstract::STATUS => SwishMassRefundRunStatus::COMPLETED->value,
            SwishMassRefundRunDomainObjectAbstract::COMPLETED_AT => now()->toDateTimeString(),
            SwishMassRefundRunDomainObjectAbstract::LAST_ACTIVITY_AT => now()->toDateTimeString(),
        ]);

        $this->logger->info('Swish mass refund completed', [
            'run_id' => $run->getId(),
            'event_id' => $run->getEventId(),
            'initiated_by_user_id' => $run->getInitiatedByUserId(),
            'total_orders' => $run->getTotalOrders(),
            'total_amount' => $run->getTotalAmount(),
            'succeeded' => $run->getSucceededCount(),
            'succeeded_amount' => $run->getSucceededAmount(),
            'failed' => $run->getFailedCount(),
            'skipped' => $run->getSkippedCount(),
            'manual' => $run->getManualCount(),
        ]);

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->findFirst($run->getEventId());

        $recipient = $event?->getOrganizer()?->getEmail();

        if ($event === null || $recipient === null) {
            $this->logger->error('Could not notify organizer about completed Swish mass refund: no organizer email', [
                'run_id' => $run->getId(),
                'event_id' => $run->getEventId(),
            ]);

            return;
        }

        $failedItems = $this->itemsRepository->findWhere([
            SwishMassRefundItemDomainObjectAbstract::RUN_ID => $run->getId(),
            SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::FAILED->value,
        ]);

        $this->mailer
            ->to($recipient)
            ->bcc($failedItems->isEmpty() ? [] : array_filter([config('app.alerts_email')]))
            ->locale(config('app.locale'))
            ->send(new SwishMassRefundCompletedMail(
                run: $run,
                event: $event,
                failedItems: $failedItems,
            ));
    }
}
