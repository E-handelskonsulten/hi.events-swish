<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Swish;

use HiEvents\DomainObjects\Enums\SwishEnvironment;
use HiEvents\DomainObjects\OrganizerSwishSettingDomainObject;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Repository\Interfaces\OrganizerSwishSettingsRepositoryInterface;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishConnectionConfigDTO;
use Illuminate\Config\Repository;

class SwishConfigurationService
{
    public const SOURCE_ORGANIZER = 'organizer';

    public const SOURCE_ENV = 'env';

    public function __construct(
        private readonly Repository $config,
        private readonly OrganizerSwishSettingsRepositoryInterface $organizerSwishSettingsRepository,
    ) {}

    /**
     * @throws SwishConfigurationException
     */
    public function resolveForOrganizer(int $organizerId): SwishConnectionConfigDTO
    {
        $settings = $this->organizerSwishSettingsRepository->findByOrganizerId($organizerId);

        if ($settings !== null && $settings->getEnabled()) {
            return $this->fromOrganizerSettings($settings);
        }

        return $this->fromEnvironment();
    }

    public function isEnabledForOrganizer(int $organizerId): bool
    {
        try {
            $this->resolveForOrganizer($organizerId);

            return true;
        } catch (SwishConfigurationException) {
            return false;
        }
    }

    /**
     * @throws SwishConfigurationException
     */
    public function fromOrganizerSettings(OrganizerSwishSettingDomainObject $settings): SwishConnectionConfigDTO
    {
        if (! $settings->isUsable()) {
            throw new SwishConfigurationException(__('Swish settings for this organizer are incomplete.'));
        }

        $environment = SwishEnvironment::tryFrom((string) $settings->getEnvironment());

        if ($environment === null) {
            throw new SwishConfigurationException(__('Swish environment is invalid.'));
        }

        return new SwishConnectionConfigDTO(
            environment: $environment,
            payeeAlias: (string) $settings->getPayeeAlias(),
            certPath: (string) $settings->getCertPath(),
            keyPath: (string) $settings->getKeyPath(),
            keyPassphrase: $settings->getKeyPassphrase() !== null && $settings->getKeyPassphrase() !== ''
                ? $settings->getKeyPassphrase()
                : null,
            caPath: (string) $settings->getCaPath(),
            source: self::SOURCE_ORGANIZER,
        );
    }

    /**
     * @throws SwishConfigurationException
     */
    public function fromEnvironment(): SwishConnectionConfigDTO
    {
        if (! $this->config->get('swish.enabled')) {
            throw new SwishConfigurationException(__('Swish payments are not configured.'));
        }

        $environment = SwishEnvironment::tryFrom((string) $this->config->get('swish.environment'));
        $payeeAlias = (string) $this->config->get('swish.payee_alias');
        $certPath = (string) $this->config->get('swish.cert_path');
        $keyPath = (string) $this->config->get('swish.key_path');
        $caPath = (string) $this->config->get('swish.ca_path');

        if ($environment === null || $payeeAlias === '' || $certPath === '' || $keyPath === '' || $caPath === '') {
            throw new SwishConfigurationException(__('Swish payments are not configured.'));
        }

        $passphrase = $this->config->get('swish.key_passphrase');

        return new SwishConnectionConfigDTO(
            environment: $environment,
            payeeAlias: $payeeAlias,
            certPath: $certPath,
            keyPath: $keyPath,
            keyPassphrase: $passphrase !== null && $passphrase !== '' ? (string) $passphrase : null,
            caPath: $caPath,
            source: self::SOURCE_ENV,
        );
    }

    public function paymentCallbackUrl(): string
    {
        return $this->callbackUrl('/public/webhooks/swish/payments');
    }

    public function refundCallbackUrl(): string
    {
        return $this->callbackUrl('/public/webhooks/swish/refunds');
    }

    public function minimumStatusPollIntervalSeconds(): int
    {
        return max(1, (int) $this->config->get('swish.status_poll_min_interval_seconds', 3));
    }

    private function callbackUrl(string $path): string
    {
        return rtrim((string) $this->config->get('swish.callback_base_url'), '/').$path;
    }
}
