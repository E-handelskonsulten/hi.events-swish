<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Status\SwishMassRefundRunStatus;
use Illuminate\Support\Collection;

class SwishMassRefundRunDomainObject extends Generated\SwishMassRefundRunDomainObjectAbstract
{
    /** @var Collection<int, SwishMassRefundItemDomainObject>|null */
    private ?Collection $items = null;

    public function getStatusEnum(): SwishMassRefundRunStatus
    {
        return SwishMassRefundRunStatus::from($this->getStatus());
    }

    public function isActive(): bool
    {
        return $this->getStatusEnum()->isActive();
    }

    /**
     * @return Collection<int, SwishMassRefundItemDomainObject>|null
     */
    public function getItems(): ?Collection
    {
        return $this->items;
    }

    /**
     * @param  Collection<int, SwishMassRefundItemDomainObject>|null  $items
     */
    public function setItems(?Collection $items): self
    {
        $this->items = $items;

        return $this;
    }
}
