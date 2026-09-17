<?php

namespace HiEvents\Resources\Tax;

use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Resources\BaseResource;

/**
 * @mixin TaxAndFeesDomainObject
 */
class TaxAndFeeResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->getId(),
            'account_id' => $this->getAccountId(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            /** @var 'PERCENTAGE'|'FIXED'|'FIXED_PLUS_PERCENTAGE' */
            'calculation_type' => $this->getCalculationType(),
            'rate' => $this->getRate(),
            'fixed_amount' => $this->getFixedAmount(),
            'is_inclusive' => $this->getIsInclusive(),
            'is_active' => $this->getIsActive(),
            'is_default' => $this->getIsDefault(),
            'type' => $this->getType(),
        ];
    }
}
