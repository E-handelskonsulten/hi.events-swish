<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\Swish;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Organizer\Swish\OrganizerSwishSettingsResource;
use HiEvents\Services\Application\Handlers\Organizer\Payment\Swish\GetOrganizerSwishSettingsHandler;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;
use Illuminate\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetOrganizerSwishSettingsAction extends BaseAction
{
    public function __construct(
        private readonly GetOrganizerSwishSettingsHandler $handler,
        private readonly Repository $config,
    ) {}

    /**
     * Get organizer Swish settings
     *
     * `data` is null until the organizer has saved Swish settings. `meta.environment_fallback_configured`
     * tells whether installation-wide `SWISH_*` environment settings would be used instead.
     */
    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $settings = $this->handler->handle($organizerId);

        return $this->jsonResponse([
            'data' => $settings ? (new OrganizerSwishSettingsResource($settings))->toArray($request) : null,
            'meta' => [
                'environment_fallback_configured' => (bool) $this->config->get('swish.enabled'),
                'environment_fallback_payee_alias' => $this->config->get('swish.enabled') ? $this->config->get('swish.payee_alias') : null,
                'environment_fallback_source' => SwishConfigurationService::SOURCE_ENV,
            ],
        ]);
    }
}
