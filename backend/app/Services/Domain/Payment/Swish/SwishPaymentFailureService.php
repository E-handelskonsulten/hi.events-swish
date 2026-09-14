<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SwishPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishPaymentRequestDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishPaymentFailureService
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws Throwable
     */
    public function fail(SwishPaymentDomainObject $payment, SwishPaymentRequestDTO $remote): SwishPaymentDomainObject
    {
        $status = $remote->getStatusEnum();

        if ($status === null || ! $status->isFailure()) {
            return $payment;
        }

        return $this->transition($payment, $status, $remote->errorCode, $remote->errorMessage, $remote->raw);
    }

    /**
     * @throws Throwable
     */
    public function markCancelled(SwishPaymentDomainObject $payment): SwishPaymentDomainObject
    {
        return $this->transition($payment, SwishPaymentStatus::CANCELLED, null, null, null);
    }

    /**
     * @throws Throwable
     */
    public function markExpired(SwishPaymentDomainObject $payment): SwishPaymentDomainObject
    {
        return $this->transition($payment, SwishPaymentStatus::EXPIRED, null, null, null);
    }

    /**
     * @throws Throwable
     */
    private function transition(
        SwishPaymentDomainObject $payment,
        SwishPaymentStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?array $payload,
    ): SwishPaymentDomainObject {
        return $this->databaseManager->transaction(function () use ($payment, $status, $errorCode, $errorMessage, $payload) {
            /** @var SwishPaymentDomainObject $freshPayment */
            $freshPayment = $this->swishPaymentsRepository->findById($payment->getId());

            if ($freshPayment->isTerminal()) {
                return $freshPayment;
            }

            $attributes = [
                SwishPaymentDomainObjectAbstract::STATUS => $status->value,
                SwishPaymentDomainObjectAbstract::ERROR_CODE => $errorCode,
                SwishPaymentDomainObjectAbstract::ERROR_MESSAGE => $errorMessage !== null ? Str::limit($errorMessage, 500) : null,
            ];

            if ($payload !== null && $payload !== []) {
                $attributes[SwishPaymentDomainObjectAbstract::CALLBACK_PAYLOAD] = $payload;
            }

            $updated = $this->swishPaymentsRepository->updateFromArray($freshPayment->getId(), $attributes);

            if (in_array($status, [SwishPaymentStatus::DECLINED, SwishPaymentStatus::ERROR], true)) {
                $this->orderRepository->updateWhere(
                    attributes: [OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_FAILED->name],
                    where: [
                        OrderDomainObjectAbstract::ID => $freshPayment->getOrderId(),
                        OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::AWAITING_PAYMENT->name,
                    ],
                );
            }

            $this->logger->info('Swish payment transitioned to non-paid terminal state', [
                'swish_payment_id' => $updated->getId(),
                'instruction_uuid' => $updated->getInstructionUuid(),
                'order_id' => $updated->getOrderId(),
                'status' => $status->value,
                'error_code' => $errorCode,
            ]);

            return $updated;
        });
    }
}
