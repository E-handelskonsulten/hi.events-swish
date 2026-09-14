<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\Swish;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Enums\SwishEnvironment;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Organizer\Swish\UpsertOrganizerSwishSettingsRequest;
use HiEvents\Resources\Organizer\Swish\OrganizerSwishSettingsResource;
use HiEvents\Services\Application\Handlers\Organizer\Payment\Swish\DTO\UpsertOrganizerSwishSettingsDTO;
use HiEvents\Services\Application\Handlers\Organizer\Payment\Swish\UpsertOrganizerSwishSettingsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class UpsertOrganizerSwishSettingsAction extends BaseAction
{
    public function __construct(
        private readonly UpsertOrganizerSwishSettingsHandler $handler,
    ) {}

    /**
     * Save organizer Swish settings
     *
     * When `enabled` is true the certificate files are loaded and an mTLS request is made to Swish
     * before saving; a failing handshake is returned as a validation error on `cert_path`.
     *
     * @throws ValidationException
     */
    public function __invoke(UpsertOrganizerSwishSettingsRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class, Role::ADMIN);

        try {
            $settings = $this->handler->handle(new UpsertOrganizerSwishSettingsDTO(
                organizerId: $organizerId,
                accountId: $this->getAuthenticatedAccountId(),
                enabled: $request->boolean('enabled'),
                environment: SwishEnvironment::from($request->validated('environment')),
                payeeAlias: $request->validated('payee_alias'),
                certPath: $request->validated('cert_path'),
                keyPath: $request->validated('key_path'),
                caPath: $request->validated('ca_path'),
                keyPassphraseProvided: $request->has('key_passphrase'),
                keyPassphrase: $request->validated('key_passphrase'),
            ));
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (SwishConfigurationException $exception) {
            throw ValidationException::withMessages([
                'cert_path' => $exception->getMessage(),
            ]);
        }

        return $this->resourceResponse(OrganizerSwishSettingsResource::class, $settings);
    }
}
