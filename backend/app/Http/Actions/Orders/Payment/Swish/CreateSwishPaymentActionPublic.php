<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Payment\Swish;

use HiEvents\DomainObjects\Enums\SwishCheckoutFlow;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Exceptions\Swish\SwishTransientException;
use HiEvents\Exceptions\Swish\SwishValidationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\CreateSwishPaymentRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Order\Swish\SwishPaymentResourcePublic;
use HiEvents\Services\Application\Handlers\Order\Payment\Swish\CreateSwishPaymentHandler;
use HiEvents\Services\Application\Handlers\Order\Payment\Swish\DTO\CreateSwishPaymentDTO;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CreateSwishPaymentActionPublic extends BaseAction
{
    public function __construct(
        private readonly CreateSwishPaymentHandler $handler,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create Swish payment request
     *
     * Creates (or reuses) the pending Swish payment request for a reserved order. M-commerce
     * requests return a `payment_request_token` for the Swish app deeplink; e-commerce requests
     * require `payer_alias` and push the request to the buyer's Swish app.
     *
     * @throws Throwable
     */
    public function __invoke(CreateSwishPaymentRequest $request, int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $payment = $this->handler->handle(new CreateSwishPaymentDTO(
                eventId: $eventId,
                orderShortId: $orderShortId,
                flow: SwishCheckoutFlow::from($request->validated('flow')),
                payerAlias: $request->validated('payer_alias'),
            ));
        } catch (ResourceConflictException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (SwishConfigurationException $exception) {
            $this->logger->error('Swish payment attempted with invalid configuration', [
                'event_id' => $eventId,
                'order_short_id' => $orderShortId,
                'error' => $exception->getMessage(),
            ]);

            return $this->errorResponse(__('Swish is not available for this event right now.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SwishValidationException $exception) {
            return $this->errorResponse($this->translateSwishError($exception), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SwishTransientException $exception) {
            return $this->errorResponse(__('Swish is temporarily unavailable. Please try again in a moment.'), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (SwishApiException $exception) {
            return $this->errorResponse(__('Swish could not create the payment. Please try again.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->resourceResponse(
            resource: SwishPaymentResourcePublic::class,
            data: $payment,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }

    private function translateSwishError(SwishValidationException $exception): string
    {
        return match ($exception->getFirstErrorCode()) {
            'RP06' => __('There is already an active Swish request for this mobile number. Open the Swish app to confirm or cancel it, then try again.'),
            'BE18', 'ACMT03' => __('This mobile number is not connected to Swish.'),
            'AM06', 'AM02', 'PA02' => __('This amount cannot be paid with Swish.'),
            'ACMT01', 'ACMT07', 'RP01' => __('The merchant Swish account is not ready to receive payments.'),
            default => $exception->getMessage(),
        };
    }
}
