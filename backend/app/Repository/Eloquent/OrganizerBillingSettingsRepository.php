<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OrganizerBillingSettingDomainObject;
use HiEvents\Models\OrganizerBillingSetting;
use HiEvents\Repository\Interfaces\OrganizerBillingSettingsRepositoryInterface;

/**
 * @extends BaseRepository<OrganizerBillingSettingDomainObject>
 */
class OrganizerBillingSettingsRepository extends BaseRepository implements OrganizerBillingSettingsRepositoryInterface
{
    protected function getModel(): string
    {
        return OrganizerBillingSetting::class;
    }

    public function getDomainObject(): string
    {
        return OrganizerBillingSettingDomainObject::class;
    }
}
