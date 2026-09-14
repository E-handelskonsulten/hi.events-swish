<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Generated\SwishRefundDomainObjectAbstract;
use HiEvents\DomainObjects\Status\SwishRefundStatus;
use HiEvents\DomainObjects\SwishRefundDomainObject;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishRefundsRepositoryInterface;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishRefundDTO;
use HiEvents\Services\Infrastructure\Swish\SwishApiClient;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishRefundStatusReconciliationService
{
    public function __construct(
        private readonly SwishRefundsRepositoryInterface $swishRefundsRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SwishConfigurationService $swishConfigurationService,
        private readonly SwishApiClient $swishApiClient,
        private readonly SwishRefundCompletionService $completionService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     * @throws Throwable
     */
    public function reconcile(SwishRefundDomainObject $refund): SwishRefundDomainObject
    {
        if ($refund->isTerminal()) {
            return $refund;
        }

        $order = $this->orderRepository->findById($refund->getOrderId());
        $event = $this->eventRepository->findById($order->getEventId());
        $connection = $this->swishConfigurationService->resolveForOrganizer($event->getOrganizerId());

        $logContext = [
            'swish_refund_id' => $refund->getId(),
            'instruction_uuid' => $refund->getInstructionUuid(),
            'order_id' => $order->getId(),
            'local_status' => $refund->getStatus(),
        ];

        $remote = SwishRefundDTO::fromSwishPayload(
            $this->swishApiClient->getRefund($connection, $refund->getInstructionUuid(), $logContext),
        );

        $this->swishRefundsRepository->updateFromArray($refund->getId(), [
            SwishRefundDomainObjectAbstract::LAST_POLLED_AT => now()->toDateTimeString(),
            SwishRefundDomainObjectAbstract::POLL_ATTEMPTS => $refund->getPollAttempts() + 1,
        ]);

        return $this->apply($refund, $remote, $logContext);
    }

    public function matchesLocalRecord(SwishRefundDomainObject $refund, SwishRefundDTO $remote): bool
    {
        if ($remote->id !== strtoupper($refund->getInstructionUuid())) {
            return false;
        }

        if ($remote->amount !== null && abs($remote->amount - (float) $refund->getAmount()) >= 0.005) {
            return false;
        }

        if ($remote->payerAlias !== null && $remote->payerAlias !== $refund->getPayerAlias()) {
            return false;
        }

        if ($remote->currency !== null && $remote->currency !== strtoupper($refund->getCurrency())) {
            return false;
        }

        return true;
    }

    /**
     * @throws Throwable
     */
    private function apply(SwishRefundDomainObject $refund, SwishRefundDTO $remote, array $logContext): SwishRefundDomainObject
    {
        $logContext += ['remote_status' => $remote->status];

        if (! $this->matchesLocalRecord($refund, $remote)) {
            $this->logger->error('Swish refund does not match local record, not applying status', $logContext + [
                'remote_amount' => $remote->amount,
                'local_amount' => $refund->getAmount(),
                'remote_payer_alias' => $remote->payerAlias,
                'local_payer_alias' => $refund->getPayerAlias(),
            ]);

            return $refund;
        }

        $status = $remote->getStatusEnum();

        if ($status === SwishRefundStatus::PAID) {
            return $this->completionService->complete($refund, $remote);
        }

        if ($status === SwishRefundStatus::ERROR) {
            return $this->completionService->fail($refund, $remote);
        }

        if ($status === SwishRefundStatus::DEBITED && $refund->getStatus() !== SwishRefundStatus::DEBITED->value) {
            $this->logger->info('Swish refund debited, awaiting payout confirmation', $logContext);

            return $this->swishRefundsRepository->updateFromArray($refund->getId(), [
                SwishRefundDomainObjectAbstract::STATUS => SwishRefundStatus::DEBITED->value,
            ]);
        }

        $this->logger->debug('Swish refund still pending', $logContext);

        return $refund;
    }
}
