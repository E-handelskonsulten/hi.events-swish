<?php

declare(strict_types=1);

namespace HiEvents\Resources\Organizer\Billing;

use HiEvents\DomainObjects\OrganizerBillingSettingDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin OrganizerBillingSettingDomainObject
 */
class OrganizerBillingSettingsResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'organizer_id' => $this->getOrganizerId(),
            'sms_enabled' => (bool) $this->getSmsEnabled(),
            'sms_sender_name' => $this->getSmsSenderName(),
            'sms_lead_hours' => $this->getSmsLeadHours() === null ? null : (int) $this->getSmsLeadHours(),
            'sms_fee_per_message' => (float) $this->getSmsFeePerMessage(),
            'updated_at' => $this->getUpdatedAt(),
        ];
    }
}
