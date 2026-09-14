<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Payment\Swish;

use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Order\Swish\SwishPaymentResourcePublic;
use HiEvents\Services\Application\Handlers\Order\Payment\Swish\CancelSwishPaymentHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CancelSwishPaymentActionPublic extends BaseAction
{
    public function __construct(
        private readonly CancelSwishPaymentHandler $handler,
    ) {}

    /**
     * Cancel Swish payment request
     *
     * Cancels the pending Swish payment request so the buyer can retry or switch payment method.
     * If Swish reports the request as already paid, the order is completed instead.
     *
     * @throws Throwable
     */
    public function __invoke(int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $payment = $this->handler->handle($eventId, $orderShortId);
        } catch (SwishApiException|SwishConfigurationException $exception) {
            return $this->errorResponse(__('The Swish payment request could not be cancelled. Please try again.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->resourceResponse(SwishPaymentResourcePublic::class, $payment);
    }
}
