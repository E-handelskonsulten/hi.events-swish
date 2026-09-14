<?php

declare(strict_types=1);

namespace HiEvents\Resources\Organizer\Swish;

use HiEvents\DomainObjects\OrganizerSwishSettingDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin OrganizerSwishSettingDomainObject
 */
class OrganizerSwishSettingsResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'organizer_id' => $this->getOrganizerId(),
            'enabled' => (bool) $this->getEnabled(),
            /** @var 'mss'|'production' */
            'environment' => $this->getEnvironment(),
            'payee_alias' => $this->getPayeeAlias(),
            'cert_path' => $this->getCertPath(),
            'key_path' => $this->getKeyPath(),
            'ca_path' => $this->getCaPath(),
            'has_key_passphrase' => $this->getKeyPassphrase() !== null && $this->getKeyPassphrase() !== '',
            'last_verified_at' => $this->getLastVerifiedAt(),
            'last_verification_error' => $this->getLastVerificationError(),
            'updated_at' => $this->getUpdatedAt(),
        ];
    }
}
