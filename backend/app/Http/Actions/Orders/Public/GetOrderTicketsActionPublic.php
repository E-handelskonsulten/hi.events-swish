<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Orders\Public;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Order\OrderTicketsResourcePublic;
use HiEvents\Services\Application\Handlers\Order\GetOrderTicketsPublicHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetOrderTicketsActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetOrderTicketsPublicHandler $handler,
    ) {}

    /**
     * Get all tickets on an order
     *
     * Sessionless: the order short id is the access token, exactly like the attendee short id on the single-ticket page.
     */
    public function __invoke(string $orderShortId): JsonResponse|Response
    {
        try {
            $order = $this->handler->handle($orderShortId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(OrderTicketsResourcePublic::class, $order);
    }
}
