<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Payment\Swish;

use HiEvents\DomainObjects\Generated\OrganizerSwishSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSwishSettingDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSwishSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\Payment\Swish\DTO\UpsertOrganizerSwishSettingsDTO;
use HiEvents\Services\Domain\Payment\Swish\SwishConnectivityCheckService;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishConnectionConfigDTO;
use HiEvents\Services\Infrastructure\Swish\SwishConfigurationService;
use Psr\Log\LoggerInterface;

class UpsertOrganizerSwishSettingsHandler
{
    public function __construct(
        private readonly OrganizerSwishSettingsRepositoryInterface $organizerSwishSettingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly SwishConnectivityCheckService $connectivityCheckService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SwishConfigurationException
     */
    public function handle(UpsertOrganizerSwishSettingsDTO $dto): OrganizerSwishSettingDomainObject
    {
        $organizer = $this->organizerRepository->findFirstWhere([
            'id' => $dto->organizerId,
            'account_id' => $dto->accountId,
        ]);

        if ($organizer === null) {
            throw new ResourceNotFoundException(__('Organizer not found.'));
        }

        $existing = $this->organizerSwishSettingsRepository->findByOrganizerId($dto->organizerId);

        $keyPassphrase = $dto->keyPassphraseProvided
            ? ($dto->keyPassphrase !== '' ? $dto->keyPassphrase : null)
            : $existing?->getKeyPassphrase();

        $data = [
            OrganizerSwishSettingDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
            OrganizerSwishSettingDomainObjectAbstract::ENABLED => $dto->enabled,
            OrganizerSwishSettingDomainObjectAbstract::ENVIRONMENT => $dto->environment->value,
            OrganizerSwishSettingDomainObjectAbstract::PAYEE_ALIAS => $dto->payeeAlias !== null ? preg_replace('/\D/', '', $dto->payeeAlias) : null,
            OrganizerSwishSettingDomainObjectAbstract::CERT_PATH => $dto->certPath !== null ? trim($dto->certPath) : null,
            OrganizerSwishSettingDomainObjectAbstract::KEY_PATH => $dto->keyPath !== null ? trim($dto->keyPath) : null,
            OrganizerSwishSettingDomainObjectAbstract::KEY_PASSPHRASE => $keyPassphrase,
            OrganizerSwishSettingDomainObjectAbstract::CA_PATH => $dto->caPath !== null ? trim($dto->caPath) : null,
        ];

        if ($dto->enabled) {
            $error = $this->connectivityCheckService->check(new SwishConnectionConfigDTO(
                environment: $dto->environment,
                payeeAlias: (string) $data[OrganizerSwishSettingDomainObjectAbstract::PAYEE_ALIAS],
                certPath: (string) $data[OrganizerSwishSettingDomainObjectAbstract::CERT_PATH],
                keyPath: (string) $data[OrganizerSwishSettingDomainObjectAbstract::KEY_PATH],
                keyPassphrase: $keyPassphrase,
                caPath: (string) $data[OrganizerSwishSettingDomainObjectAbstract::CA_PATH],
                source: SwishConfigurationService::SOURCE_ORGANIZER,
            ));

            if ($error !== null) {
                $this->logger->info('Swish settings rejected by connectivity check', [
                    'organizer_id' => $dto->organizerId,
                    'environment' => $dto->environment->value,
                    'error' => $error,
                ]);

                throw new SwishConfigurationException($error);
            }

            $data[OrganizerSwishSettingDomainObjectAbstract::LAST_VERIFIED_AT] = now()->toDateTimeString();
            $data[OrganizerSwishSettingDomainObjectAbstract::LAST_VERIFICATION_ERROR] = null;
        }

        $settings = $existing
            ? $this->organizerSwishSettingsRepository->updateFromArray($existing->getId(), $data)
            : $this->organizerSwishSettingsRepository->create($data);

        $this->logger->info('Swish settings saved', [
            'organizer_id' => $dto->organizerId,
            'enabled' => $dto->enabled,
            'environment' => $dto->environment->value,
            'payee_alias' => $settings->getPayeeAlias(),
        ]);

        return $settings;
    }
}
