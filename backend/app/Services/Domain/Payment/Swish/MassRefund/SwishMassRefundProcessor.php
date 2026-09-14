<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish\MassRefund;

use HiEvents\DomainObjects\Enums\OrderAuditAction;
use HiEvents\DomainObjects\Generated\SwishMassRefundItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishMassRefundRunDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishRefundDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\Status\SwishMassRefundItemStatus;
use HiEvents\DomainObjects\Status\SwishMassRefundRunStatus;
use HiEvents\DomainObjects\Status\SwishRefundStatus;
use HiEvents\DomainObjects\SwishMassRefundItemDomainObject;
use HiEvents\DomainObjects\SwishMassRefundRunDomainObject;
use HiEvents\DomainObjects\SwishRefundDomainObject;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Repository\Interfaces\OrderAuditLogRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishMassRefundItemsRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishMassRefundRunsRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishRefundsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Swish\RefundSwishOrderHandler;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishMassRefundProcessor
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly Repository $config,
        private readonly SwishMassRefundRunsRepositoryInterface $runsRepository,
        private readonly SwishMassRefundItemsRepositoryInterface $itemsRepository,
        private readonly SwishRefundsRepositoryInterface $swishRefundsRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly RefundSwishOrderHandler $refundSwishOrderHandler,
        private readonly SwishMassRefundCompletionService $completionService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Processes one batch of a run: syncs refunds that already have a result, requests up to
     * `batch_size` new refunds and completes the run once nothing is open. The scheduler triggers
     * a batch every five seconds for each active run, which is what throttles the Swish traffic.
     */
    public function processBatch(int $runId): void
    {
        /** @var SwishMassRefundRunDomainObject|null $run */
        $run = $this->runsRepository->findFirst($runId);

        if ($run === null || ! $run->isActive()) {
            return;
        }

        $run = $this->runsRepository->updateFromArray($run->getId(), [
            SwishMassRefundRunDomainObjectAbstract::STATUS => SwishMassRefundRunStatus::RUNNING->value,
            SwishMassRefundRunDomainObjectAbstract::STARTED_AT => $run->getStartedAt() ?? now()->toDateTimeString(),
            SwishMassRefundRunDomainObjectAbstract::LAST_ACTIVITY_AT => now()->toDateTimeString(),
        ]);

        $this->syncRequestedItems($run);

        foreach ($this->claimPendingItems($run->getId(), $this->batchSize()) as $itemId) {
            $this->processItem($run, $itemId);
        }

        $counts = $this->refreshCounts($run->getId());

        if ($counts['open'] === 0) {
            $this->completionService->complete($run->getId());
        }
    }

    /**
     * @return int[]
     */
    public function claimPendingItems(int $runId, int $limit): array
    {
        $rows = $this->databaseManager->select(<<<'SQL'
            UPDATE swish_mass_refund_items
            SET status = :processing, claimed_at = NOW(), updated_at = NOW()
            WHERE id IN (
                SELECT id FROM swish_mass_refund_items
                WHERE run_id = :run_id AND status = :pending AND deleted_at IS NULL
                ORDER BY id
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            )
            RETURNING id
        SQL, [
            'processing' => SwishMassRefundItemStatus::PROCESSING->value,
            'run_id' => $runId,
            'pending' => SwishMassRefundItemStatus::PENDING->value,
            'limit' => $limit,
        ]);

        $ids = array_map(fn (object $row) => (int) $row->id, $rows);
        sort($ids);

        return $ids;
    }

    public function releaseStaleProcessingItems(int $runId): int
    {
        return $this->itemsRepository->updateWhere(
            attributes: [
                SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::PENDING->value,
                SwishMassRefundItemDomainObjectAbstract::CLAIMED_AT => null,
            ],
            where: [
                SwishMassRefundItemDomainObjectAbstract::RUN_ID => $runId,
                SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::PROCESSING->value,
            ],
        );
    }

    /**
     * @return array{open: int}
     */
    public function refreshCounts(int $runId): array
    {
        $rows = $this->databaseManager->table('swish_mass_refund_items')
            ->selectRaw('status, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->where('run_id', $runId)
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $count = fn (SwishMassRefundItemStatus $status) => (int) ($rows[$status->value]->count ?? 0);

        $this->runsRepository->updateFromArray($runId, [
            SwishMassRefundRunDomainObjectAbstract::PENDING_COUNT => $count(SwishMassRefundItemStatus::PENDING) + $count(SwishMassRefundItemStatus::PROCESSING),
            SwishMassRefundRunDomainObjectAbstract::REQUESTED_COUNT => $count(SwishMassRefundItemStatus::REQUESTED),
            SwishMassRefundRunDomainObjectAbstract::SUCCEEDED_COUNT => $count(SwishMassRefundItemStatus::SUCCEEDED),
            SwishMassRefundRunDomainObjectAbstract::FAILED_COUNT => $count(SwishMassRefundItemStatus::FAILED),
            SwishMassRefundRunDomainObjectAbstract::SKIPPED_COUNT => $count(SwishMassRefundItemStatus::SKIPPED),
            SwishMassRefundRunDomainObjectAbstract::SUCCEEDED_AMOUNT => round((float) ($rows[SwishMassRefundItemStatus::SUCCEEDED->value]->amount ?? 0), 2),
            SwishMassRefundRunDomainObjectAbstract::LAST_ACTIVITY_AT => now()->toDateTimeString(),
        ]);

        return [
            'open' => $count(SwishMassRefundItemStatus::PENDING)
                + $count(SwishMassRefundItemStatus::PROCESSING)
                + $count(SwishMassRefundItemStatus::REQUESTED),
        ];
    }

    private function syncRequestedItems(SwishMassRefundRunDomainObject $run): void
    {
        $requested = $this->itemsRepository->findWhere([
            SwishMassRefundItemDomainObjectAbstract::RUN_ID => $run->getId(),
            SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::REQUESTED->value,
        ]);

        if ($requested->isEmpty()) {
            return;
        }

        $refunds = $this->swishRefundsRepository
            ->findWhereIn(SwishRefundDomainObjectAbstract::ID, $requested->map->getSwishRefundId()->filter()->values()->all())
            ->keyBy(fn (SwishRefundDomainObject $refund) => $refund->getId());

        /** @var SwishMassRefundItemDomainObject $item */
        foreach ($requested as $item) {
            /** @var SwishRefundDomainObject|null $refund */
            $refund = $refunds->get($item->getSwishRefundId());

            if ($refund === null) {
                continue;
            }

            if ($refund->getStatus() === SwishRefundStatus::PAID->value) {
                $this->itemsRepository->updateFromArray($item->getId(), [
                    SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::SUCCEEDED->value,
                    SwishMassRefundItemDomainObjectAbstract::PROCESSED_AT => now()->toDateTimeString(),
                ]);
            } elseif ($refund->getStatus() === SwishRefundStatus::ERROR->value) {
                $this->itemsRepository->updateFromArray($item->getId(), [
                    SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::FAILED->value,
                    SwishMassRefundItemDomainObjectAbstract::ERROR_CODE => $refund->getErrorCode(),
                    SwishMassRefundItemDomainObjectAbstract::ERROR_MESSAGE => $refund->getErrorMessage() ?? __('Swish reported an error for this refund.'),
                    SwishMassRefundItemDomainObjectAbstract::PROCESSED_AT => now()->toDateTimeString(),
                ]);

                $this->audit($run, $item, OrderAuditAction::MASS_REFUND_FAILED, [
                    'error_code' => $refund->getErrorCode(),
                    'error' => $refund->getErrorMessage(),
                ]);
            }
        }
    }

    private function processItem(SwishMassRefundRunDomainObject $run, int $itemId): void
    {
        /** @var SwishMassRefundItemDomainObject $item */
        $item = $this->itemsRepository->findById($itemId);
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository->findFirst($item->getOrderId());

        $this->itemsRepository->updateFromArray($item->getId(), [
            SwishMassRefundItemDomainObjectAbstract::ATTEMPTS => $item->getAttempts() + 1,
        ]);

        if ($order === null) {
            $this->skip($run, $item, __('The order no longer exists.'));

            return;
        }

        $inFlight = $this->findInFlightRefund($order);

        if ($inFlight !== null) {
            $this->markRequested($run, $item, $inFlight);

            return;
        }

        $remaining = round($order->getTotalGross() - $order->getTotalRefunded(), 2);

        if ($order->getRefundStatus() === OrderRefundStatus::REFUNDED->name || $remaining < SwishMassRefundPreflightService::MINIMUM_REFUND_AMOUNT) {
            $this->skip($run, $item, __('The order has already been refunded.'));

            return;
        }

        try {
            $this->refundSwishOrderHandler->handle(new RefundOrderDTO(
                event_id: $run->getEventId(),
                order_id: $order->getId(),
                amount: $remaining,
                notify_buyer: (bool) $run->getNotifyBuyers(),
                cancel_order: (bool) $run->getCancelOrders(),
            ));
        } catch (RefundNotPossibleException $exception) {
            $this->skip($run, $item, $exception->getMessage());

            return;
        } catch (SwishApiException $exception) {
            $this->fail($run, $item, $exception->getFirstErrorCode(), $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            $this->logger->error('Swish mass refund item failed unexpectedly', [
                'run_id' => $run->getId(),
                'item_id' => $item->getId(),
                'order_id' => $order->getId(),
                'error' => $exception->getMessage(),
            ]);

            $this->fail($run, $item, null, $exception->getMessage());

            return;
        }

        $refund = $this->findInFlightRefund($this->orderRepository->findById($order->getId()));

        if ($refund === null) {
            $this->fail($run, $item, null, __('The refund request was not recorded.'));

            return;
        }

        $this->markRequested($run, $item, $refund, $remaining);
    }

    private function findInFlightRefund(OrderDomainObject $order): ?SwishRefundDomainObject
    {
        if ($order->getRefundStatus() !== OrderRefundStatus::REFUND_PENDING->name) {
            return null;
        }

        return $this->swishRefundsRepository
            ->findWhereIn(
                field: SwishRefundDomainObjectAbstract::STATUS,
                values: [SwishRefundStatus::CREATED->value, SwishRefundStatus::DEBITED->value],
                additionalWhere: [SwishRefundDomainObjectAbstract::ORDER_ID => $order->getId()],
            )
            ->sortByDesc(fn (SwishRefundDomainObject $refund) => $refund->getId())
            ->first();
    }

    private function markRequested(
        SwishMassRefundRunDomainObject $run,
        SwishMassRefundItemDomainObject $item,
        SwishRefundDomainObject $refund,
        ?float $amount = null,
    ): void {
        $this->itemsRepository->updateFromArray($item->getId(), [
            SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::REQUESTED->value,
            SwishMassRefundItemDomainObjectAbstract::SWISH_REFUND_ID => $refund->getId(),
            SwishMassRefundItemDomainObjectAbstract::AMOUNT => $amount ?? (float) $refund->getAmount(),
            SwishMassRefundItemDomainObjectAbstract::ERROR_CODE => null,
            SwishMassRefundItemDomainObjectAbstract::ERROR_MESSAGE => null,
        ]);

        $this->audit($run, $item, OrderAuditAction::MASS_REFUND_REQUESTED, [
            'swish_refund_id' => $refund->getId(),
            'instruction_uuid' => $refund->getInstructionUuid(),
            'amount' => $amount ?? (float) $refund->getAmount(),
        ]);
    }

    private function skip(SwishMassRefundRunDomainObject $run, SwishMassRefundItemDomainObject $item, string $reason): void
    {
        $this->itemsRepository->updateFromArray($item->getId(), [
            SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::SKIPPED->value,
            SwishMassRefundItemDomainObjectAbstract::ERROR_MESSAGE => Str::limit($reason, 500),
            SwishMassRefundItemDomainObjectAbstract::PROCESSED_AT => now()->toDateTimeString(),
        ]);

        $this->audit($run, $item, OrderAuditAction::MASS_REFUND_SKIPPED, ['reason' => $reason]);
    }

    private function fail(SwishMassRefundRunDomainObject $run, SwishMassRefundItemDomainObject $item, ?string $errorCode, string $message): void
    {
        $this->itemsRepository->updateFromArray($item->getId(), [
            SwishMassRefundItemDomainObjectAbstract::STATUS => SwishMassRefundItemStatus::FAILED->value,
            SwishMassRefundItemDomainObjectAbstract::ERROR_CODE => $errorCode,
            SwishMassRefundItemDomainObjectAbstract::ERROR_MESSAGE => Str::limit($message, 500),
            SwishMassRefundItemDomainObjectAbstract::PROCESSED_AT => now()->toDateTimeString(),
        ]);

        $this->logger->warning('Swish mass refund item failed', [
            'run_id' => $run->getId(),
            'item_id' => $item->getId(),
            'order_id' => $item->getOrderId(),
            'error_code' => $errorCode,
            'error' => $message,
        ]);

        $this->audit($run, $item, OrderAuditAction::MASS_REFUND_FAILED, [
            'error_code' => $errorCode,
            'error' => $message,
        ]);
    }

    private function audit(SwishMassRefundRunDomainObject $run, SwishMassRefundItemDomainObject $item, OrderAuditAction $action, array $details): void
    {
        try {
            $this->auditLogRepository->create([
                'event_id' => $run->getEventId(),
                'order_id' => $item->getOrderId(),
                'attendee_id' => null,
                'action' => $action->value,
                'old_values' => null,
                'new_values' => $details + [
                    'mass_refund_run_id' => $run->getId(),
                    'initiated_by_user_id' => $run->getInitiatedByUserId(),
                ],
                'changed_fields' => null,
                'ip_address' => null,
                'user_agent' => null,
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to write mass refund audit log entry', [
                'run_id' => $run->getId(),
                'order_id' => $item->getOrderId(),
                'action' => $action->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function batchSize(): int
    {
        return max(1, (int) $this->config->get('swish.mass_refund.batch_size', 5));
    }
}
