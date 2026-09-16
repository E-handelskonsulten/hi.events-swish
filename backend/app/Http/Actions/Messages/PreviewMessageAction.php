<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Messages;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Message\PreviewMessageRequest;
use HiEvents\Resources\Message\MessagePreviewResource;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Application\Handlers\Message\PreviewMessageHandler;
use Illuminate\Http\JsonResponse;

class PreviewMessageAction extends BaseAction
{
    public function __construct(private readonly PreviewMessageHandler $handler) {}

    /**
     * Preview recipients and SMS cost for a message before sending it
     */
    public function __invoke(PreviewMessageRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $validated = $request->validated();

        $preview = $this->handler->handle(SendMessageDTO::fromArray([
            'event_id' => $eventId,
            'subject' => $validated['subject'] ?? '',
            'message' => $validated['message'] ?? '',
            'type' => $validated['message_type'],
            'channel' => $validated['channel'] ?? 'EMAIL',
            'purpose' => $validated['purpose'] ?? 'SERVICE',
            'sms_body' => $validated['sms_body'] ?? null,
            'is_test' => false,
            'order_id' => $validated['order_id'] ?? null,
            'attendee_ids' => $validated['attendee_ids'] ?? [],
            'product_ids' => $validated['product_ids'] ?? [],
            'order_statuses' => $validated['order_statuses'] ?? [],
            'send_copy_to_current_user' => false,
            'sent_by_user_id' => $this->getAuthenticatedUser()->getId(),
            'account_id' => $this->getAuthenticatedAccountId(),
            'event_occurrence_id' => $validated['event_occurrence_id'] ?? null,
            'event_occurrence_ids' => $validated['event_occurrence_ids'] ?? null,
        ]));

        return $this->resourceResponse(MessagePreviewResource::class, $preview);
    }
}
