<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish;

use HiEvents\DomainObjects\Enums\SwishCheckoutFlow;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SwishPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;
use HiEvents\DomainObjects\SwishPaymentDomainObject;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Repository\Interfaces\SwishPaymentsRepositoryInterface;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishConnectionConfigDTO;
use HiEvents\Services\Infrastructure\Swish\SwishApiClient;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class SwishPaymentRequestService
{
    public const CURRENCY = 'SEK';

    private const MESSAGE_MAX_LENGTH = 50;

    public function __construct(
        private readonly SwishPaymentsRepositoryInterface $swishPaymentsRepository,
        private readonly SwishApiClient $swishApiClient,
        private readonly SwishConfigurationService $swishConfigurationService,
        private readonly LoggerInterface $logger,
    ) {}

    public function createPendingRecord(
        OrderDomainObject $order,
        SwishCheckoutFlow $flow,
        ?string $payerAlias,
        SwishConnectionConfigDTO $connection,
    ): SwishPaymentDomainObject {
        return $this->swishPaymentsRepository->create([
            SwishPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
            SwishPaymentDomainObjectAbstract::INSTRUCTION_UUID => self::newInstructionUuid(),
            SwishPaymentDomainObjectAbstract::ENVIRONMENT => $connection->environment->value,
            SwishPaymentDomainObjectAbstract::FLOW => $flow->value,
            SwishPaymentDomainObjectAbstract::PAYEE_ALIAS => $connection->payeeAlias,
            SwishPaymentDomainObjectAbstract::PAYER_ALIAS => $payerAlias,
            SwishPaymentDomainObjectAbstract::AMOUNT => $order->getTotalGross(),
            SwishPaymentDomainObjectAbstract::CURRENCY => self::CURRENCY,
            SwishPaymentDomainObjectAbstract::STATUS => SwishPaymentStatus::CREATED->value,
        ]);
    }

    /**
     * @throws SwishApiException
     * @throws SwishConfigurationException
     */
    public function submit(
        SwishPaymentDomainObject $payment,
        OrderDomainObject $order,
        EventDomainObject $event,
        SwishConnectionConfigDTO $connection,
    ): SwishPaymentDomainObject {
        $payload = [
            'payeeAlias' => $connection->payeeAlias,
            'currency' => self::CURRENCY,
            'amount' => number_format((float) $payment->getAmount(), 2, '.', ''),
            'callbackUrl' => $this->swishConfigurationService->paymentCallbackUrl(),
            'payeePaymentReference' => (string) $order->getId(),
            'message' => self::buildMessage($event->getTitle()),
        ];

        if ($payment->getPayerAlias() !== null) {
            $payload['payerAlias'] = $payment->getPayerAlias();
        }

        $logContext = [
            'order_id' => $order->getId(),
            'order_short_id' => $order->getShortId(),
            'swish_payment_id' => $payment->getId(),
            'flow' => $payment->getFlow(),
        ];

        try {
            $response = $this->swishApiClient->createPaymentRequest(
                connection: $connection,
                instructionUuid: $payment->getInstructionUuid(),
                payload: $payload,
                logContext: $logContext,
            );
        } catch (SwishApiException $exception) {
            $this->swishPaymentsRepository->updateFromArray($payment->getId(), [
                SwishPaymentDomainObjectAbstract::STATUS => SwishPaymentStatus::ERROR->value,
                SwishPaymentDomainObjectAbstract::ERROR_CODE => $exception->getFirstErrorCode(),
                SwishPaymentDomainObjectAbstract::ERROR_MESSAGE => Str::limit($exception->getMessage(), 500),
            ]);

            $this->logger->error('Swish payment request creation failed', $logContext + [
                'instruction_uuid' => $payment->getInstructionUuid(),
                'error_codes' => $exception->getErrorCodes(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->logger->info('Swish payment request created', $logContext + [
            'instruction_uuid' => $payment->getInstructionUuid(),
            'status' => SwishPaymentStatus::CREATED->value,
            'has_token' => $response->paymentRequestToken !== null,
        ]);

        return $this->swishPaymentsRepository->updateFromArray($payment->getId(), [
            SwishPaymentDomainObjectAbstract::LOCATION_URL => $response->location,
            SwishPaymentDomainObjectAbstract::PAYMENT_REQUEST_TOKEN => $response->paymentRequestToken,
        ]);
    }

    public static function newInstructionUuid(): string
    {
        return strtoupper(str_replace('-', '', (string) Str::uuid()));
    }

    public static function buildMessage(?string $eventTitle): string
    {
        $message = preg_replace('/[^a-zA-Z0-9åäöÅÄÖ :;.,?!()"]/u', '', (string) $eventTitle) ?? '';
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        if ($message === '') {
            $message = __('Tickets');
        }

        return mb_substr($message, 0, self::MESSAGE_MAX_LENGTH);
    }
}
