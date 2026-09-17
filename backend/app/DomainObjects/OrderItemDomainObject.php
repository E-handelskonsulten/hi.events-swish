<?php

namespace HiEvents\DomainObjects;

use HiEvents\Helper\Currency;

class OrderItemDomainObject extends Generated\OrderItemDomainObjectAbstract
{
    private ?ProductPriceDomainObject $productPrice = null;

    public ?ProductDomainObject $product = null;

    public ?OrderDomainObject $order = null;

    private ?EventOccurrenceDomainObject $eventOccurrence = null;

    public function getTotalBeforeDiscount(): float
    {
        return Currency::round($this->getPriceBeforeDiscount() * $this->getQuantity());
    }

    public function getProductPrice(): ?ProductPriceDomainObject
    {
        return $this->productPrice;
    }

    public function setProductPrice(?ProductPriceDomainObject $tier): self
    {
        $this->productPrice = $tier;

        return $this;
    }

    public function getProduct(): ?ProductDomainObject
    {
        return $this->product;
    }

    public function setProduct(?ProductDomainObject $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getOrder(): ?OrderDomainObject
    {
        return $this->order;
    }

    public function setOrder(?OrderDomainObject $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function getEventOccurrence(): ?EventOccurrenceDomainObject
    {
        return $this->eventOccurrence;
    }

    public function setEventOccurrence(?EventOccurrenceDomainObject $eventOccurrence): self
    {
        $this->eventOccurrence = $eventOccurrence;

        return $this;
    }

    public function getInclusiveTax(): float
    {
        $rollup = $this->getTaxesAndFeesRollup();
        if (is_string($rollup)) {
            $rollup = json_decode($rollup, true) ?: [];
        }

        return (float) collect($rollup['taxes'] ?? [])
            ->filter(fn (array $tax) => ! empty($tax['inclusive']))
            ->sum('value');
    }

    public function getReportedTax(): float
    {
        return (float) ($this->getTotalTax() ?? 0) + $this->getInclusiveTax();
    }
}
