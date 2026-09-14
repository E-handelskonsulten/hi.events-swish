<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events\Swish\MassRefund;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\Swish\SwishMassRefundConfirmationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Event\Swish\StartSwishMassRefundRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Event\Swish\SwishMassRefundRunResource;
use HiEvents\Services\Application\Handlers\Event\Swish\MassRefund\DTO\StartSwishMassRefundDTO;
use HiEvents\Services\Application\Handlers\Event\Swish\MassRefund\StartSwishMassRefundHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StartSwishMassRefundAction extends BaseAction
{
    public function __construct(
        private readonly StartSwishMassRefundHandler $handler,
    ) {}

    /**
     * Start a Swish mass refund
     *
     * Refunds every automatically refundable Swish order of the event through individual Swish
     * refund requests processed in the background. `confirmation` must match the event title exactly.
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(StartSwishMassRefundRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class, Role::ADMIN);

        try {
            $run = $this->handler->handle(new StartSwishMassRefundDTO(
                eventId: $eventId,
                accountId: $this->getAuthenticatedAccountId(),
                initiatedBy: $this->getAuthenticatedUser(),
                confirmation: (string) $request->validated('confirmation'),
                notifyBuyers: $request->boolean('notify_buyers'),
                cancelOrders: $request->boolean('cancel_orders'),
            ));
        } catch (SwishMassRefundConfirmationException $exception) {
            throw ValidationException::withMessages([
                'confirmation' => $exception->getMessage(),
            ]);
        } catch (ResourceConflictException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->resourceResponse(
            resource: SwishMassRefundRunResource::class,
            data: $run,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
