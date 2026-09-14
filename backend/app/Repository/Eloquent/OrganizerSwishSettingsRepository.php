<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerSwishSettingDomainObject;
use HiEvents\Models\OrganizerSwishSetting;
use HiEvents\Repository\Interfaces\OrganizerSwishSettingsRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerSwishSettingDomainObject>
 */
class OrganizerSwishSettingsRepository extends BaseRepository implements OrganizerSwishSettingsRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerSwishSetting::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerSwishSettingDomainObject::class;
    }

    public function findByOrganizerId(int $organizerId): ?OrganizerSwishSettingDomainObject
    {
        return $this->findFirstWhere(['organizer_id' => $organizerId]);
    }
}
