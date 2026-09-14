<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects;

class OrganizerSwishSettingDomainObject extends Generated\OrganizerSwishSettingDomainObjectAbstract
{
    public function isUsable(): bool
    {
        return (bool) $this->getEnabled()
            && $this->getPayeeAlias() !== null
            && $this->getCertPath() !== null
            && $this->getKeyPath() !== null
            && $this->getCaPath() !== null;
    }
}
