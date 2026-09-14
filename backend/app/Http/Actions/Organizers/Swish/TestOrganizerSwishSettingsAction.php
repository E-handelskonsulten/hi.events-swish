<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\Swish;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\Payment\Swish\TestOrganizerSwishSettingsHandler;
use Illuminate\Http\JsonResponse;

class TestOrganizerSwishSettingsAction extends BaseAction
{
    public function __construct(
        private readonly TestOrganizerSwishSettingsHandler $handler,
    ) {}

    /**
     * Test the organizer's Swish connection
     *
     * Resolves the effective Swish configuration (organizer settings, falling back to the installation
     * environment), performs an mTLS request against Swish and reports the outcome without saving changes
     * other than the verification timestamp.
     */
    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class, Role::ADMIN);

        $result = $this->handler->handle($organizerId);

        return $this->jsonResponse([
            'data' => [
                'success' => $result->success,
                'message' => $result->message,
                /** @var 'mss'|'production'|null */
                'environment' => $result->environment,
                'payee_alias' => $result->payeeAlias,
                /** @var 'organizer'|'environment'|null */
                'source' => $result->source,
            ],
        ]);
    }
}
