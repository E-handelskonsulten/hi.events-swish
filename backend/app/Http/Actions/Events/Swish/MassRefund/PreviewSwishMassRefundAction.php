<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events\Swish\MassRefund;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Event\Swish\MassRefund\PreviewSwishMassRefundHandler;
use Illuminate\Http\JsonResponse;

class PreviewSwishMassRefundAction extends BaseAction
{
    public function __construct(
        private readonly PreviewSwishMassRefundHandler $handler,
    ) {}

    /**
     * Preview a Swish mass refund
     *
     * Lists the paid Swish orders of the event that can be refunded automatically, the total amount,
     * a breakdown per ticket type, and the orders that need manual handling with the reason.
     */
    public function __invoke(int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class, Role::ADMIN);

        return $this->jsonResponse(
            data: $this->handler->handle($eventId)->toArray(),
            wrapInData: true,
        );
    }
}
