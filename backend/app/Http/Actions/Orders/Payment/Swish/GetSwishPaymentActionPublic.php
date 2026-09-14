<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Payment\Swish;

use Dedoc\Scramble\Attributes\QueryParameter;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Order\Swish\SwishPaymentResourcePublic;
use HiEvents\Services\Application\Handlers\Order\Payment\Swish\GetSwishPaymentHandler;
use Illuminate\Http\JsonResponse;
use Throwable;

class GetSwishPaymentActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetSwishPaymentHandler $handler,
    ) {}

    /**
     * Get Swish payment status
     *
     * Returns the latest Swish payment request for the order. While the request is pending the
     * status is refreshed from Swish (rate limited), so this endpoint doubles as the lost-callback
     * safety net for the checkout page.
     *
     * @throws Throwable
     */
    #[QueryParameter('session_identifier', description: 'Checkout session identifier issued when the order was created.', type: 'string')]
    public function __invoke(int $eventId, string $orderShortId): JsonResponse
    {
        $payment = $this->handler->handle($eventId, $orderShortId);

        return $this->resourceResponse(SwishPaymentResourcePublic::class, $payment);
    }
}
