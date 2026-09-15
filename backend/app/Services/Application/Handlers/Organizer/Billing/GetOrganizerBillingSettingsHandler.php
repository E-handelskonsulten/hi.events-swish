<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Billing;

use HiEvents\DomainObjects\Generated\OrganizerBillingSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerBillingSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerBillingSettingsRepositoryInterface;

class GetOrganizerBillingSettingsHandler
{
    public function __construct(
        private readonly OrganizerBillingSettingsRepositoryInterface $organizerBillingSettingsRepository,
    ) {}

    public function handle(int $organizerId): ?OrganizerBillingSettingDomainObject
    {
        return $this->organizerBillingSettingsRepository->findFirstWhere([
            OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);
    }
}
