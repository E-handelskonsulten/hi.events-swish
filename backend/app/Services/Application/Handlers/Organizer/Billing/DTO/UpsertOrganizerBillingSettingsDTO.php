<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Billing\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UpsertOrganizerBillingSettingsDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $organizerId,
        public readonly int $accountId,
        public readonly bool $smsEnabled,
        public readonly ?string $smsSenderName,
    ) {}
}
