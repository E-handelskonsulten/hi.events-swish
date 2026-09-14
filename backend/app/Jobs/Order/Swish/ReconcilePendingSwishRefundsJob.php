<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Order\Swish;

use HiEvents\DomainObjects\Generated\SwishRefundDomainObjectAbstract;
use HiEvents\DomainObjects\Status\SwishRefundStatus;
use HiEvents\DomainObjects\SwishRefundDomainObject;
use HiEvents\Repository\Interfaces\SwishRefundsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\SwishRefundStatusReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

class ReconcilePendingSwishRefundsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(
        SwishRefundsRepositoryInterface $swishRefundsRepository,
        SwishRefundStatusReconciliationService $reconciliationService,
        LoggerInterface $logger,
    ): void {
        $pending = $swishRefundsRepository->findWhereIn(
            field: SwishRefundDomainObjectAbstract::STATUS,
            values: [SwishRefundStatus::CREATED->value, SwishRefundStatus::DEBITED->value],
        );

        if ($pending->isEmpty()) {
            return;
        }

        $logger->debug('Reconciling pending Swish refunds', ['count' => $pending->count()]);

        /** @var SwishRefundDomainObject $refund */
        foreach ($pending as $refund) {
            try {
                $reconciliationService->reconcile($refund);
            } catch (Throwable $exception) {
                $logger->error('Failed to reconcile pending Swish refund', [
                    'swish_refund_id' => $refund->getId(),
                    'instruction_uuid' => $refund->getInstructionUuid(),
                    'order_id' => $refund->getOrderId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
