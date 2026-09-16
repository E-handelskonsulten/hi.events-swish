<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Marketing\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class MarketingOptOutStatusDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $organizerName,
        public readonly bool $optedIn,
    ) {}
}
