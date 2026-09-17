<?php

namespace HiEvents\Services\Domain\Tax;

use Illuminate\Support\Collection;

class TaxAndFeeOrderRollupService
{
    public function rollup(Collection $orderItems): array
    {
        $orderRollup = [];

        foreach ($orderItems as $orderItem) {
            $itemTaxRollUp = $orderItem->getTaxesAndFeesRollup();

            foreach ($itemTaxRollUp as $type => $taxesAndFees) {
                $orderRollup[$type] ??= [];

                foreach ($taxesAndFees as $taxOrFee) {
                    $foundIndex = array_search($taxOrFee['name'], array_column($orderRollup[$type], 'name'), true);
                    if ($foundIndex === false) {
                        $orderRollup[$type][] = [
                            'name' => $taxOrFee['name'],
                            'value' => $taxOrFee['value'],
                            'rate' => $taxOrFee['rate'],
                            'fixed_amount' => $taxOrFee['fixed_amount'] ?? null,
                            'type' => $taxOrFee['type'],
                            'inclusive' => ! empty($taxOrFee['inclusive']),
                            'fee_value' => (float) ($taxOrFee['fee_value'] ?? 0),
                        ];
                    } else {
                        $orderRollup[$type][$foundIndex]['value'] += $taxOrFee['value'];
                        $orderRollup[$type][$foundIndex]['fee_value'] = ($orderRollup[$type][$foundIndex]['fee_value'] ?? 0) + (float) ($taxOrFee['fee_value'] ?? 0);
                    }
                }
            }
        }

        return $orderRollup;
    }
}
