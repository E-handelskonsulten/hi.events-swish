<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Swish;

use HiEvents\Exceptions\Swish\SwishApiException;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishConnectionConfigDTO;
use HiEvents\Services\Infrastructure\Swish\SwishApiClient;
use Psr\Log\LoggerInterface;

class SwishConnectivityCheckService
{
    public function __construct(
        private readonly SwishApiClient $swishApiClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Proves the certificate files load, the mTLS handshake succeeds and Swish accepts the merchant
     * identity. Returns a human-readable error message, or null when the connection works.
     */
    public function check(SwishConnectionConfigDTO $connection): ?string
    {
        try {
            $this->swishApiClient->verifyConnection($connection);
        } catch (SwishConfigurationException $exception) {
            return $exception->getMessage();
        } catch (SwishApiException $exception) {
            $this->logger->warning('Swish connectivity check failed', [
                'environment' => $connection->environment->value,
                'payee_alias' => $connection->payeeAlias,
                'http_status' => $exception->getHttpStatus(),
                'error' => $exception->getMessage(),
            ]);

            return match ($exception->getHttpStatus()) {
                401 => __('Swish rejected the certificate. Check that the certificate, private key and passphrase belong together and match the selected environment.'),
                403 => __('Swish accepted the certificate but the Swish number does not belong to it.'),
                null => __('Could not reach Swish: :error', ['error' => $exception->getMessage()]),
                default => $exception->getMessage(),
            };
        }

        return null;
    }
}
