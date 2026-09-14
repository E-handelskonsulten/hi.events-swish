<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Payment\Swish;

use HiEvents\DomainObjects\Generated\OrganizerSwishSettingDomainObjectAbstract;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Repository\Interfaces\OrganizerSwishSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\Payment\Swish\DTO\SwishConnectionTestResultDTO;
use HiEvents\Services\Domain\Payment\Swish\SwishConnectivityCheckService;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;

class TestOrganizerSwishSettingsHandler
{
    public function __construct(
        private readonly SwishConfigurationService $swishConfigurationService,
        private readonly SwishConnectivityCheckService $connectivityCheckService,
        private readonly OrganizerSwishSettingsRepositoryInterface $organizerSwishSettingsRepository,
    ) {}

    public function handle(int $organizerId): SwishConnectionTestResultDTO
    {
        try {
            $connection = $this->swishConfigurationService->resolveForOrganizer($organizerId);
        } catch (SwishConfigurationException $exception) {
            return new SwishConnectionTestResultDTO(
                success: false,
                message: $exception->getMessage(),
                environment: null,
                payeeAlias: null,
                source: null,
            );
        }

        $error = $this->connectivityCheckService->check($connection);

        $settings = $this->organizerSwishSettingsRepository->findByOrganizerId($organizerId);

        if ($settings !== null && $connection->source === SwishConfigurationService::SOURCE_ORGANIZER) {
            $this->organizerSwishSettingsRepository->updateFromArray($settings->getId(), [
                OrganizerSwishSettingDomainObjectAbstract::LAST_VERIFIED_AT => $error === null ? now()->toDateTimeString() : $settings->getLastVerifiedAt(),
                OrganizerSwishSettingDomainObjectAbstract::LAST_VERIFICATION_ERROR => $error,
            ]);
        }

        return new SwishConnectionTestResultDTO(
            success: $error === null,
            message: $error ?? __('Swish accepted the certificate and the Swish number.'),
            environment: $connection->environment->value,
            payeeAlias: $connection->payeeAlias,
            source: $connection->source,
        );
    }
}
