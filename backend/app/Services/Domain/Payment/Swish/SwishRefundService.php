<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Generated\SwishRefundDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\SwishRefundStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\DomainObjects\SwishRefundDomainObject;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Repository\Interfaces\SwishRefundsRepositoryInterface;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishConnectionConfigDTO;
use HiEvents\Services\Infrastructure\Swish\SwishApiClient;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;
use HiEvents\Values\MoneyValue;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class SwishRefundService
{
    public function __construct(
        private readonly SwishRefundsRepositoryInterface $swishRefundsRepository,
        private readonly SwishApiClient $swishApiClient,
        private readonly SwishConfigurationService $swishConfigurationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     */
    public function createRefund(
        OrderDomainObject $order,
        SwishPaymentDomainObject $payment,
        MoneyValue $amount,
        SwishConnectionConfigDTO $connection,
    ): SwishRefundDomainObject {
        $refund = $this->swishRefundsRepository->create([
            SwishRefundDomainObjectAbstract::ORDER_ID => $order->getId(),
            SwishRefundDomainObjectAbstract::SWISH_PAYMENT_ID => $payment->getId(),
            SwishRefundDomainObjectAbstract::INSTRUCTION_UUID => SwishPaymentRequestService::newInstructionUuid(),
            SwishRefundDomainObjectAbstract::ENVIRONMENT => $connection->environment->value,
            SwishRefundDomainObjectAbstract::ORIGINAL_PAYMENT_REFERENCE => (string) $payment->getPaymentReference(),
            SwishRefundDomainObjectAbstract::PAYER_ALIAS => $connection->payeeAlias,
            SwishRefundDomainObjectAbstract::PAYEE_ALIAS => $payment->getPayerAlias(),
            SwishRefundDomainObjectAbstract::AMOUNT => $amount->toFloat(),
            SwishRefundDomainObjectAbstract::CURRENCY => SwishPaymentRequestService::CURRENCY,
            SwishRefundDomainObjectAbstract::STATUS => SwishRefundStatus::CREATED->value,
        ]);

        $payload = [
            'originalPaymentReference' => (string) $payment->getPaymentReference(),
            'callbackUrl' => $this->swishConfigurationService->refundCallbackUrl(),
            'payerAlias' => $connection->payeeAlias,
            'amount' => number_format($amount->toFloat(), 2, '.', ''),
            'currency' => SwishPaymentRequestService::CURRENCY,
            'payerPaymentReference' => (string) $order->getId(),
            'message' => SwishPaymentRequestService::buildMessage(__('Refund').' '.$order->getPublicId()),
        ];

        if ($payment->getPayerAlias() !== null) {
            $payload['payeeAlias'] = $payment->getPayerAlias();
        }

        $logContext = [
            'order_id' => $order->getId(),
            'order_short_id' => $order->getShortId(),
            'swish_payment_id' => $payment->getId(),
            'swish_refund_id' => $refund->getId(),
            'instruction_uuid' => $refund->getInstructionUuid(),
        ];

        try {
            $location = $this->swishApiClient->createRefund(
                connection: $connection,
                instructionUuid: $refund->getInstructionUuid(),
                payload: $payload,
                logContext: $logContext,
            );
        } catch (SwishApiException $exception) {
            $this->swishRefundsRepository->updateFromArray($refund->getId(), [
                SwishRefundDomainObjectAbstract::STATUS => SwishRefundStatus::ERROR->value,
                SwishRefundDomainObjectAbstract::ERROR_CODE => $exception->getFirstErrorCode(),
                SwishRefundDomainObjectAbstract::ERROR_MESSAGE => Str::limit($exception->getMessage(), 500),
            ]);

            $this->logger->error('Swish refund creation failed', $logContext + [
                'error_codes' => $exception->getErrorCodes(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->logger->info('Swish refund created', $logContext + [
            'amount' => $amount->toFloat(),
            'status' => SwishRefundStatus::CREATED->value,
        ]);

        return $this->swishRefundsRepository->updateFromArray($refund->getId(), [
            SwishRefundDomainObjectAbstract::LOCATION_URL => $location ?: null,
        ]);
    }
}
