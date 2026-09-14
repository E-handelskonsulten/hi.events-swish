<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events\Swish\MassRefund;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Event\Swish\SwishMassRefundRunResource;
use HiEvents\Services\Application\Handlers\Event\Swish\MassRefund\RetrySwishMassRefundHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RetrySwishMassRefundAction extends BaseAction
{
    public function __construct(
        private readonly RetrySwishMassRefundHandler $handler,
    ) {}

    /**
     * Retry the failed refunds of a completed Swish mass refund run
     */
    public function __invoke(int $eventId, int $runId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class, Role::ADMIN);

        try {
            $run = $this->handler->handle($eventId, $runId);
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ResourceConflictException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->resourceResponse(SwishMassRefundRunResource::class, $run);
    }
}
