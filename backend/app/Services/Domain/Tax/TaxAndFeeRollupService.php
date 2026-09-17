<?php

namespace HiEvents\Services\Domain\Tax;

namespace HiEvents\Services\Domain\Tax;

use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use Illuminate\Support\Str;

class TaxAndFeeRollupService
{
    private array $rollUp = [];

    public function getRollUp(): array
    {
        return $this->rollUp;
    }

    public function resetRollUp(): void
    {
        $this->rollUp = [];
    }

    /**
     * Taxes added on top of the price. Inclusive taxes (Swedish VAT) are
     * already inside the price and are reported separately.
     */
    public function getTotalTaxes(): float
    {
        return collect($this->rollUp['taxes'] ?? [])
            ->reject(fn (array $tax) => ! empty($tax['inclusive']))
            ->sum('value');
    }

    public function getTotalInclusiveTaxes(): float
    {
        return collect($this->rollUp['taxes'] ?? [])
            ->filter(fn (array $tax) => ! empty($tax['inclusive']))
            ->sum('value');
    }

    public function getTotalFees(): float
    {
        return collect($this->rollUp['fees'] ?? [])->sum('value');
    }

    public function getTotalTaxesAndFees(): float
    {
        return $this->getTotalTaxes() + $this->getTotalFees();
    }

    public function addToRollUp(TaxAndFeesDomainObject $taxOrFee, float $amount, bool $inclusive = false, bool $feePortion = false): void
    {
        $type = strtolower(Str::plural($taxOrFee->getType()));
        $name = $taxOrFee->getName();

        $this->rollUp[$type] ??= [];

        $foundIndex = array_search($name, array_column($this->rollUp[$type], 'name'), true);
        if ($foundIndex === false) {
            $this->rollUp[$type][] = [
                'name' => $name,
                'rate' => $taxOrFee->getRate(),
                'fixed_amount' => $taxOrFee->getFixedAmount(),
                'type' => $taxOrFee->getCalculationType(),
                'value' => $amount,
                'inclusive' => $inclusive,
                'fee_value' => $feePortion ? $amount : 0.0,
            ];
        } else {
            $this->rollUp[$type][$foundIndex]['value'] += $amount;
            if ($feePortion) {
                $this->rollUp[$type][$foundIndex]['fee_value'] = ($this->rollUp[$type][$foundIndex]['fee_value'] ?? 0.0) + $amount;
            }
        }
    }
}
