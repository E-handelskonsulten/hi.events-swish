<?php

namespace HiEvents\Services\Domain\Tax;

use HiEvents\DomainObjects\Enums\TaxCalculationType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Services\Domain\Tax\DTO\TaxCalculationResponse;
use InvalidArgumentException;

class TaxAndFeeCalculationService
{
    private TaxAndFeeRollupService $taxRollupService;

    public function __construct(TaxAndFeeRollupService $taxRollupService)
    {
        $this->taxRollupService = $taxRollupService;
    }

    public function calculateTaxAndFeesForProductPrice(
        ProductDomainObject $product,
        ProductPriceDomainObject $price,
    ): TaxCalculationResponse {
        return $this->calculateTaxAndFeesForProduct($product, $price->getPrice());
    }

    public function calculateTaxAndFeesForProduct(
        ProductDomainObject $product,
        float $price,
        int $quantity = 1
    ): TaxCalculationResponse {
        $this->taxRollupService->resetRollUp();

        $fees = $product->getFees()
            ?->sum(fn ($taxOrFee) => $this->calculateFee($taxOrFee, $price, $quantity)) ?: 0.00;

        $taxRates = $product->getTaxRates() ?? collect();

        $taxFees = $taxRates
            ->reject(fn (TaxAndFeesDomainObject $tax) => $tax->getIsInclusive())
            ->sum(fn ($taxOrFee) => $this->calculateFee($taxOrFee, $price + $fees, $quantity));

        // Inclusive taxes (Swedish VAT) are carved out of the ticket price for
        // reporting only; they never change what the buyer pays.
        $inclusiveTaxes = $taxRates
            ->filter(fn (TaxAndFeesDomainObject $tax) => $tax->getIsInclusive())
            ->sum(fn (TaxAndFeesDomainObject $tax) => $this->calculateInclusiveTax($tax, $price, $quantity));

        return new TaxCalculationResponse(
            feeTotal: $fees ? ($fees * $quantity) : 0.00,
            taxTotal: $taxFees ? ($taxFees * $quantity) : 0.00,
            rollUp: $this->taxRollupService->getRollUp(),
            inclusiveTaxTotal: $inclusiveTaxes ? ($inclusiveTaxes * $quantity) : 0.00,
        );
    }

    private function calculateInclusiveTax(TaxAndFeesDomainObject $tax, float $price, int $quantity): float
    {
        if ($price === 0.00 || $tax->getCalculationType() !== TaxCalculationType::PERCENTAGE->name) {
            $this->taxRollupService->addToRollUp($tax, 0, inclusive: true);

            return 0.00;
        }

        $amount = round($price - $price / (1 + $tax->getRate() / 100), 2);

        $this->taxRollupService->addToRollUp($tax, $amount * $quantity, inclusive: true);

        return $amount;
    }

    private function calculateFee(TaxAndFeesDomainObject $taxOrFee, float $price, int $quantity): float
    {
        // We do not charge a tax or fee on items which are free of charge
        if ($price === 0.00) {
            $this->taxRollupService->addToRollUp($taxOrFee, 0);

            return 0.00;
        }

        $amount = match ($taxOrFee->getCalculationType()) {
            TaxCalculationType::FIXED->name => $taxOrFee->getRate(),
            TaxCalculationType::PERCENTAGE->name => ($price * $taxOrFee->getRate()) / 100,
            TaxCalculationType::FIXED_PLUS_PERCENTAGE->name => round(
                (float) $taxOrFee->getFixedAmount() + ($price * $taxOrFee->getRate()) / 100,
                2,
            ),
            default => throw new InvalidArgumentException(__('Invalid calculation type')),
        };

        $this->taxRollupService->addToRollUp($taxOrFee, $amount * $quantity);

        return $amount;
    }
}
