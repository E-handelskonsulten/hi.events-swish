<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\Billing;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Organizer\Billing\UpsertOrganizerBillingSettingsRequest;
use HiEvents\Resources\Organizer\Billing\OrganizerBillingSettingsResource;
use HiEvents\Services\Application\Handlers\Organizer\Billing\DTO\UpsertOrganizerBillingSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Billing\UpsertOrganizerBillingSettingsHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UpsertOrganizerBillingSettingsAction extends BaseAction
{
    public function __construct(
        private readonly UpsertOrganizerBillingSettingsHandler $handler,
    ) {}

    /**
     * Save organizer SMS delivery settings
     */
    public function __invoke(UpsertOrganizerBillingSettingsRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class, Role::ADMIN);

        try {
            $settings = $this->handler->handle(new UpsertOrganizerBillingSettingsDTO(
                organizerId: $organizerId,
                accountId: $this->getAuthenticatedAccountId(),
                smsEnabled: $request->boolean('sms_enabled'),
                smsSenderName: $request->validated('sms_sender_name'),
                smsLeadHours: $request->validated('sms_lead_hours') === null ? null : (int) $request->validated('sms_lead_hours'),
            ));
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return $this->resourceResponse(OrganizerBillingSettingsResource::class, $settings);
    }
}
