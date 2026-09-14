<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Enums\SwishCheckoutFlow;
use HiEvents\DomainObjects\Enums\SwishEnvironment;
use HiEvents\DomainObjects\Status\SwishPaymentStatus;

class SwishPaymentDomainObject extends Generated\SwishPaymentDomainObjectAbstract
{
    private ?OrderDomainObject $order = null;

    public function getOrder(): ?OrderDomainObject
    {
        return $this->order;
    }

    public function setOrder(?OrderDomainObject $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function getStatusEnum(): SwishPaymentStatus
    {
        return SwishPaymentStatus::from($this->getStatus());
    }

    public function getFlowEnum(): SwishCheckoutFlow
    {
        return SwishCheckoutFlow::from($this->getFlow());
    }

    public function getEnvironmentEnum(): SwishEnvironment
    {
        return SwishEnvironment::from($this->getEnvironment());
    }

    public function isTerminal(): bool
    {
        return $this->getStatusEnum()->isTerminal();
    }
}
