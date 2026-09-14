<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Payment\Swish\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class SwishConnectionTestResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $environment,
        public readonly ?string $payeeAlias,
        public readonly ?string $source,
    ) {}
}
