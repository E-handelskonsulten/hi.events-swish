<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\Billing;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Organizer\Billing\OrganizerBillingSettingsResource;
use HiEvents\Services\Application\Handlers\Organizer\Billing\GetOrganizerBillingSettingsHandler;
use Illuminate\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetOrganizerBillingSettingsAction extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerBillingSettingsHandler $handler,
        private readonly Repository $config,
    ) {}

    /**
     * Get organizer SMS delivery settings
     *
     * `data` is null until the organizer has saved settings.
     */
    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $settings = $this->handler->handle($organizerId);

        return $this->jsonResponse([
            'data' => $settings ? (new OrganizerBillingSettingsResource($settings))->toArray($request) : null,
            'meta' => [
                'sms_configured' => (bool) $this->config->get('sms.enabled'),
                'default_sms_sender_name' => $this->config->get('sms.default_sender'),
                'default_sms_fee_per_message' => (float) $this->config->get('billing.default_sms_fee_per_message'),
            ],
        ]);
    }
}
