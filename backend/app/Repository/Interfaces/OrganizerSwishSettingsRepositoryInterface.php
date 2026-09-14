<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OrganizerSwishSettingDomainObject;

/**
 * @extends RepositoryInterface<OrganizerSwishSettingDomainObject>
 */
interface OrganizerSwishSettingsRepositoryInterface extends RepositoryInterface
{
    public function findByOrganizerId(int $organizerId): ?OrganizerSwishSettingDomainObject;
}
