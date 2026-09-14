<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish\MassRefund;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SwishMassRefundItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishMassRefundRunDomainObjectAbstract;
use HiEvents\DomainObjects\Status\SwishMassRefundItemStatus;
use HiEvents\DomainObjects\Status\SwishMassRefundRunStatus;
use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Jobs\Order\Swish\ProcessSwishMassRefundRunJob;
use HiEvents\Repository\Interfaces\SwishMassRefundItemsRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO\SwishMassRefundOrderDTO;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO\SwishMassRefundPreviewDTO;
use HiEvents\Services\Domain\Payment\Swish\MassRefund\DTO\SwishMassRefundTicketTypeDTO;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishMassRefundRunService
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SwishMassRefundPreflightService $preflightService,
        private readonly SwishMassRefundRunsRepositoryInterface $runsRepository,
        private readonly SwishMassRefundItemsRepositoryInterface $itemsRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceConflictException
     * @throws Throwable
     */
    public function start(
        EventDomainObject $event,
        UserDomainObject $initiatedBy,
        int $accountId,
        bool $notifyBuyers,
        bool $cancelOrders,
    ): SwishMassRefundRunDomainObject {
        $run = $this->databaseManager->transaction(function () use ($event, $initiatedBy, $accountId, $notifyBuyers, $cancelOrders) {
            $this->databaseManager->statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['swish-mass-refund:'.$event->getId()]);

            $preview = $this->preflightService->preview($event);

            if ($preview->active_run_id !== null) {
                throw new ResourceConflictException(__('A mass refund is already running for this event.'));
            }

            if ($preview->refundable_count === 0) {
                throw new ResourceConflictException(__('There are no Swish orders that can be refunded automatically for this event.'));
            }

            /** @var SwishMassRefundRunDomainObject $run */
            $run = $this->runsRepository->create([
                SwishMassRefundRunDomainObjectAbstract::EVENT_ID => $event->getId(),
                SwishMassRefundRunDomainObjectAbstract::ACCOUNT_ID => $accountId,
                SwishMassRefundRunDomainObjectAbstract::INITIATED_BY_USER_ID => $initiatedBy->getId(),
                SwishMassRefundRunDomainObjectAbstract::INITIATED_BY_NAME => trim($initiatedBy->getFirstName().' '.($initiatedBy->getLastName() ?? '')),
                SwishMassRefundRunDomainObjectAbstract::STATUS => SwishMassRefundRunStatus::PENDING->value,
                SwishMassRefundRunDomainObjectAbstract::CURRENCY => $preview->currency,
                SwishMassRefundRunDomainObjectAbstract::TOTAL_ORDERS => $preview->refundable_count,
                SwishMassRefundRunDomainObjectAbstract::TOTAL_AMOUNT => $preview->total_amount,
                SwishMassRefundRunDomainObjectAbstract::MANUAL_COUNT => $preview->manual_count,
                SwishMassRefundRunDomainObjectAbstract::PENDING_COUNT => $preview->refundable_count,
                SwishMassRefundRunDomainObjectAbstract::NOTIFY_BUYERS => $notifyBuyers,
                SwishMassRefundRunDomainObjectAbstract::CANCEL_ORDERS => $cancelOrders,
                SwishMassRefundRunDomainObjectAbstract::SUMMARY => $this->buildSummary($preview),
                SwishMassRefundRunDomainObjectAbstract::LAST_ACTIVITY_AT => now()->toDateTimeString(),
            ]);

            foreach ($preview->refundable_orders as $order) {
                $this->itemsRepository->create([
                    SwishMassRefundItemDomainObjectAbstract::RUN_ID => $run->getId(),
                    SwishMassRefundItemDomainObjectAbstract::ORDER_ID => $order->order_id,
                    SwishMassRefundItemDomainObjectAbstract::ORDER_PUBLIC_ID => $order->public_id,
                    SwishMassRefundItemDomainObjectAbstract::BUYER_NAME => $order->buyer_name,
                    SwishMassRefundItemDomainObjectAbstract::BUYER_EMAIL => $order->buyer_email,
                    SwishMassRefundItemDomainObjectAbstract::AMOUNT => $order->amount,
                    SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::PENDING->value,
                ]);
            }

            return $run;
        });

        $this->logger->info('Swish mass refund started', [
            'run_id' => $run->getId(),
            'event_id' => $event->getId(),
            'initiated_by_user_id' => $initiatedBy->getId(),
            'total_orders' => $run->getTotalOrders(),
            'total_amount' => $run->getTotalAmount(),
            'currency' => $run->getCurrency(),
            'notify_buyers' => $notifyBuyers,
            'cancel_orders' => $cancelOrders,
        ]);

        ProcessSwishMassRefundRunJob::dispatch($run->getId());

        return $run;
    }

    /**
     * @throws ResourceConflictException
     */
    public function retryFailed(SwishMassRefundRunDomainObject $run): SwishMassRefundRunDomainObject
    {
        if ($run->isActive()) {
            throw new ResourceConflictException(__('The mass refund is still running.'));
        }

        $reset = $this->itemsRepository->updateWhere(
            attributes: [
                SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::PENDING->value,
                SwishMassRefundItemDomainObjectAbstract::ERROR_CODE => null,
                SwishMassRefundItemDomainObjectAbstract::ERROR_MESSAGE => null,
                SwishMassRefundItemDomainObjectAbstract::CLAIMED_AT => null,
            ],
            where: [
                SwishMassRefundItemDomainObjectAbstract::RUN_ID => $run->getId(),
                SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::FAILED->value,
            ],
        );

        if ($reset === 0) {
            throw new ResourceConflictException(__('There are no failed refunds to retry.'));
        }

        /** @var SwishMassRefundRunDomainObject $updated */
        $updated = $this->runsRepository->updateFromArray($run->getId(), [
            SwishMassRefundRunDomainObjectAbstract::STATUS => SwishMassRefundRunStatus::RUNNING->value,
            SwishMassRefundRunDomainObjectAbstract::COMPLETED_AT => null,
            SwishMassRefundRunDomainObjectAbstract::LAST_ACTIVITY_AT => now()->toDateTimeString(),
            SwishMassRefundRunDomainObjectAbstract::PENDING_COUNT => $run->getPendingCount() + $reset,
            SwishMassRefundRunDomainObjectAbstract::FAILED_COUNT => max(0, $run->getFailedCount() - $reset),
        ]);

        $this->logger->info('Swish mass refund retry requested', [
            'run_id' => $run->getId(),
            'event_id' => $run->getEventId(),
            'items_reset' => $reset,
        ]);

        ProcessSwishMassRefundRunJob::dispatch($run->getId());

        return $updated;
    }

    private function buildSummary(SwishMassRefundPreviewDTO $preview): array
    {
        return [
            'ticket_types' => array_map(fn (SwishMassRefundTicketTypeDTO $dto) => $dto->toArray(), $preview->ticket_types),
            'manual_orders' => array_map(fn (SwishMassRefundOrderDTO $dto) => $dto->toArray(), $preview->manual_orders),
            'manual_amount' => $preview->manual_amount,
        ];
    }
}
