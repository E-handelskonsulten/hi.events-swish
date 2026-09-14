<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Swish\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SwishEnvironment;

class SwishConnectionConfigDTO extends BaseDataObject
{
    public function __construct(
        public readonly SwishEnvironment $environment,
        public readonly string $payeeAlias,
        public readonly string $certPath,
        public readonly string $keyPath,
        public readonly ?string $keyPassphrase,
        public readonly string $caPath,
        public readonly string $source,
    ) {}

    public function baseUrl(): string
    {
        return $this->environment->baseUrl();
    }
}
