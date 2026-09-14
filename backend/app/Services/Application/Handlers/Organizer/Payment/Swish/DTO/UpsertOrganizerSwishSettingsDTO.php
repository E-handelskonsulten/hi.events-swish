<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Payment\Swish\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SwishEnvironment;

class UpsertOrganizerSwishSettingsDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly int $accountId,
        public readonly bool $enabled,
        public readonly SwishEnvironment $environment,
        public readonly ?string $payeeAlias,
        public readonly ?string $certPath,
        public readonly ?string $keyPath,
        public readonly ?string $caPath,
        public readonly bool $keyPassphraseProvided,
        public readonly ?string $keyPassphrase = null,
    ) {}
}
