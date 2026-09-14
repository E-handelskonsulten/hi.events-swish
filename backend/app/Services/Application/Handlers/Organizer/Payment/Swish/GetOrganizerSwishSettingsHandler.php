<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Payment\Swish;

use HiEvents\DomainObjects\OrganizerSwishSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSwishSettingsRepositoryInterface;

class GetOrganizerSwishSettingsHandler
{
    public function __construct(
        private readonly OrganizerSwishSettingsRepositoryInterface $organizerSwishSettingsRepository,
    ) {}

    public function handle(int $organizerId): ?OrganizerSwishSettingDomainObject
    {
        return $this->organizerSwishSettingsRepository->findByOrganizerId($organizerId);
    }
}
