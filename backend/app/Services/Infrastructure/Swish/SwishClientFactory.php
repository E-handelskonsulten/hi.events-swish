<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Swish;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use HiEvents\Exceptions\Swish\SwishConfigurationException;
use HiEvents\Services\Infrastructure\Swish\DTO\SwishConnectionConfigDTO;
use Illuminate\Config\Repository;

class SwishClientFactory
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * @throws SwishConfigurationException
     */
    public function create(SwishConnectionConfigDTO $connection): Client
    {
        $this->assertReadable($connection->certPath, __('Swish client certificate'));
        $this->assertReadable($connection->keyPath, __('Swish private key'));
        $this->assertReadable($connection->caPath, __('Swish root CA certificate'));

        return new Client([
            'base_uri' => $connection->baseUrl(),
            RequestOptions::TIMEOUT => (int) $this->config->get('swish.timeout_seconds', 10),
            RequestOptions::CONNECT_TIMEOUT => (int) $this->config->get('swish.connect_timeout_seconds', 5),
            RequestOptions::CERT => $connection->keyPassphrase !== null
                ? [$connection->certPath, $connection->keyPassphrase]
                : $connection->certPath,
            RequestOptions::SSL_KEY => $connection->keyPassphrase !== null
                ? [$connection->keyPath, $connection->keyPassphrase]
                : $connection->keyPath,
            RequestOptions::VERIFY => $connection->caPath,
            RequestOptions::HTTP_ERRORS => true,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @throws SwishConfigurationException
     */
    private function assertReadable(string $path, string $label): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SwishConfigurationException(__(':label is missing or not readable at :path', [
                'label' => $label,
                'path' => $path,
            ]));
        }
    }
}
