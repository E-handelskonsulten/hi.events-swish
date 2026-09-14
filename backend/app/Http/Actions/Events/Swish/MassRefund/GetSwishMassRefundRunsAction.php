<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events\Swish\MassRefund;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Event\Swish\SwishMassRefundRunResource;
use HiEvents\Services\Application\Handlers\Event\Swish\MassRefund\GetSwishMassRefundRunsHandler;
use Illuminate\Http\JsonResponse;

class GetSwishMassRefundRunsAction extends BaseAction
{
    public function __construct(
        private readonly GetSwishMassRefundRunsHandler $handler,
    ) {}

    /**
     * List Swish mass refund runs for an event
     *
     * Newest first, without per-order items.
     */
    public function __invoke(int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class, Role::ADMIN);

        return $this->resourceResponse(SwishMassRefundRunResource::class, $this->handler->handle($eventId));
    }
}
