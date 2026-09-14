<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Payment\Swish;

use HiEvents\DomainObjects\Generated\SwishPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Swish\SwishPaymentStatusReconciliationService;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishPaymentRequestDTO;
use Psr\Log\LoggerInterface;
use Throwable;

class SwishPaymentCallbackHandler
{
    public function __construct(
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly SwishPaymentStatusReconciliationService $reconciliationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(array $payload): void
    {
        $remote = SwishPaymentRequestDTO::fromSwishPayload($payload);

        if ($remote->id === '') {
            $this->logger->warning('Swish callback received without an id', ['payload' => $payload]);

            return;
        }

        /** @var SwishPaymentDomainObject|null $payment */
        $payment = $this->swishPaymentsRepository->findFirstWhere([
            SwishPaymentDomainObjectAbstract::INSTRUCTION_UUID => $remote->id,
        ]);

        if ($payment === null) {
            $this->logger->warning('Swish callback received for unknown payment request', [
                'instruction_uuid' => $remote->id,
                'remote_status' => $remote->status,
                'payee_payment_reference' => $remote->payeePaymentReference,
            ]);

            return;
        }

        $logContext = [
            'swish_payment_id' => $payment->getId(),
            'instruction_uuid' => $payment->getInstructionUuid(),
            'order_id' => $payment->getOrderId(),
            'local_status' => $payment->getStatus(),
            'remote_status' => $remote->status,
        ];

        if ($payment->isTerminal()) {
            $this->logger->info('Swish callback ignored, payment already in terminal state', $logContext);

            return;
        }

        $this->swishPaymentsRepository->updateFromArray($payment->getId(), [
            SwishPaymentDomainObjectAbstract::CALLBACK_PAYLOAD => $payload,
        ]);

        if (! $this->reconciliationService->matchesLocalRecord($payment, $remote)) {
            $this->logger->error('Swish callback payload does not match local payment record', $logContext + [
                'remote_amount' => $remote->amount,
                'local_amount' => $payment->getAmount(),
                'remote_payee_alias' => $remote->payeeAlias,
                'local_payee_alias' => $payment->getPayeeAlias(),
                'remote_reference' => $remote->payeePaymentReference,
            ]);
        }

        $this->logger->info('Processing Swish callback', $logContext);

        $this->reconciliationService->reconcile($payment);
    }
}
