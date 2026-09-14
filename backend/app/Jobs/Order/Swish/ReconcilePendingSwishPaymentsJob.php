<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Order\Swish;

use HiEvents\DomainObjects\Generated\SwishPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentStatusReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

class ReconcilePendingSwishPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(
        SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        SwishPaymentStatusReconciliationService $reconciliationService,
        LoggerInterface $logger,
    ): void {
        $pending = $swishPaymentsRepository->findWhere([
            SwishPaymentDomainObjectAbstract::STATUS => SwishPaymentStatus::CREATED->value,
        ]);

        if ($pending->isEmpty()) {
            return;
        }

        $logger->debug('Reconciling pending Swish payments', ['count' => $pending->count()]);

        /** @var SwishPaymentDomainObject $payment */
        foreach ($pending as $payment) {
            try {
                $reconciliationService->reconcile($payment);
            } catch (Throwable $exception) {
                $logger->error('Failed to reconcile pending Swish payment', [
                    'swish_payment_id' => $payment->getId(),
                    'instruction_uuid' => $payment->getInstructionUuid(),
                    'order_id' => $payment->getOrderId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
