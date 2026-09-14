<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Generated\SwishPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishConnectionConfigDTO;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishPaymentRequestDTO;
use HiEvents\Services\Infrastructure\Swish\SwishApiClient;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishPaymentStatusReconciliationService
{
    public function __construct(
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SwishConfigurationService $swishConfigurationService,
        private readonly SwishApiClient $swishApiClient,
        private readonly SwishPaymentCompletionService $completionService,
        private readonly SwishPaymentFailureService $failureService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Fetches the authoritative status from Swish and applies it. Safe to call from the callback,
     * the poller and the public status endpoint concurrently; every transition re-checks state.
     *
     * @throws SwishApiException
     * @throws SwishConfigurationException
     * @throws Throwable
     */
    public function reconcile(SwishPaymentDomainObject $payment): SwishPaymentDomainObject
    {
        if ($payment->isTerminal()) {
            return $payment;
        }

        $order = $this->orderRepository->findById($payment->getOrderId());
        $connection = $this->connectionFor($order);
        $logContext = $this->logContext($payment, $order);

        $remote = $this->swishApiClient->getPaymentRequest($connection, $payment->getInstructionUuid(), $logContext);

        $this->swishPaymentsRepository->updateFromArray($payment->getId(), [
            SwishPaymentDomainObjectAbstract::LAST_POLLED_AT => now()->toDateTimeString(),
            SwishPaymentDomainObjectAbstract::POLL_ATTEMPTS => $payment->getPollAttempts() + 1,
        ]);

        return $this->apply($payment, $order, $connection, $remote);
    }

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     * @throws Throwable
     */
    public function cancel(SwishPaymentDomainObject $payment): SwishPaymentDomainObject
    {
        if ($payment->isTerminal()) {
            return $payment;
        }

        $order = $this->orderRepository->findById($payment->getOrderId());
        $connection = $this->connectionFor($order);
        $logContext = $this->logContext($payment, $order);

        try {
            $remote = $this->swishApiClient->cancelPaymentRequest($connection, $payment->getInstructionUuid(), $logContext);
        } catch (SwishApiException $exception) {
            if ($exception->hasErrorCode(SwishApiClient::ERROR_CODE_NOT_CANCELLABLE) || $exception->getHttpStatus() === 422) {
                $this->logger->info('Swish payment request could not be cancelled, reconciling instead', $logContext);

                return $this->reconcile($payment);
            }

            if ($exception->getHttpStatus() === 404) {
                return $this->failureService->markCancelled($payment);
            }

            throw $exception;
        }

        return $this->apply($payment, $order, $connection, $remote);
    }

    public function matchesLocalRecord(SwishPaymentDomainObject $payment, SwishPaymentRequestDTO $remote): bool
    {
        if ($remote->id !== strtoupper($payment->getInstructionUuid())) {
            return false;
        }

        if ($remote->payeeAlias !== null && $remote->payeeAlias !== $payment->getPayeeAlias()) {
            return false;
        }

        if ($remote->currency !== null && $remote->currency !== strtoupper($payment->getCurrency())) {
            return false;
        }

        if ($remote->amount !== null && abs($remote->amount - (float) $payment->getAmount()) >= 0.005) {
            return false;
        }

        if ($remote->payeePaymentReference !== null && $remote->payeePaymentReference !== (string) $payment->getOrderId()) {
            return false;
        }

        return true;
    }

    /**
     * @throws Throwable
     */
    private function apply(
        SwishPaymentDomainObject $payment,
        OrderDomainObject $order,
        SwishConnectionConfigDTO $connection,
        SwishPaymentRequestDTO $remote,
    ): SwishPaymentDomainObject {
        $logContext = $this->logContext($payment, $order) + ['remote_status' => $remote->status];

        if (! $this->matchesLocalRecord($payment, $remote)) {
            $this->logger->error('Swish payment request does not match local record', $logContext + [
                'remote_amount' => $remote->amount,
                'local_amount' => $payment->getAmount(),
                'remote_payee_alias' => $remote->payeeAlias,
                'local_payee_alias' => $payment->getPayeeAlias(),
                'remote_reference' => $remote->payeePaymentReference,
            ]);

            if ($remote->getStatusEnum() === SwishPaymentStatus::PAID) {
                return $this->completionService->flagForReview($payment, $remote, SwishPaymentCompletionService::FLAG_AMOUNT_MISMATCH);
            }

            return $payment;
        }

        $status = $remote->getStatusEnum();

        if ($status === SwishPaymentStatus::PAID) {
            return $this->completionService->complete($payment, $remote);
        }

        if ($status !== null && $status->isFailure()) {
            return $this->failureService->fail($payment, $remote);
        }

        if ($order->isReservedOrderExpired()) {
            return $this->expire($payment, $connection, $logContext);
        }

        $this->logger->debug('Swish payment still pending', $logContext);

        return $payment;
    }

    /**
     * @throws Throwable
     */
    private function expire(SwishPaymentDomainObject $payment, SwishConnectionConfigDTO $connection, array $logContext): SwishPaymentDomainObject
    {
        try {
            $remote = $this->swishApiClient->cancelPaymentRequest($connection, $payment->getInstructionUuid(), $logContext);

            if ($remote->getStatusEnum() === SwishPaymentStatus::PAID) {
                return $this->completionService->complete($payment, $remote);
            }
        } catch (SwishApiException $exception) {
            if ($exception->hasErrorCode(SwishApiClient::ERROR_CODE_NOT_CANCELLABLE) || $exception->getHttpStatus() === 422) {
                $remote = $this->swishApiClient->getPaymentRequest($connection, $payment->getInstructionUuid(), $logContext);

                if ($remote->getStatusEnum() === SwishPaymentStatus::PAID) {
                    return $this->completionService->complete($payment, $remote);
                }

                if ($remote->getStatusEnum()?->isFailure()) {
                    return $this->failureService->fail($payment, $remote);
                }
            } elseif ($exception->getHttpStatus() !== 404) {
                throw $exception;
            }
        }

        $this->logger->info('Swish payment request expired with the order reservation', $logContext);

        return $this->failureService->markExpired($payment);
    }

    /**
     * @throws SwishConfigurationException
     */
    private function connectionFor(OrderDomainObject $order): SwishConnectionConfigDTO
    {
        $event = $this->eventRepository->findById($order->getEventId());

        return $this->swishConfigurationService->resolveForOrganizer($event->getOrganizerId());
    }

    private function logContext(SwishPaymentDomainObject $payment, OrderDomainObject $order): array
    {
        return [
            'swish_payment_id' => $payment->getId(),
            'instruction_uuid' => $payment->getInstructionUuid(),
            'order_id' => $order->getId(),
            'order_short_id' => $order->getShortId(),
            'local_status' => $payment->getStatus(),
        ];
    }
}
