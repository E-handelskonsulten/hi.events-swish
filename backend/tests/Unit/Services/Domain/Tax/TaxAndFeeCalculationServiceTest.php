<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Tax;

use HiEvents\DomainObjects\Enums\TaxCalculationType;
use HiEvents\DomainObjects\Enums\TaxType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Services\Domain\Tax\TaxAndFeeCalculationService;
use HiEvents\Services\Domain\Tax\TaxAndFeeRollupService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaxAndFeeCalculationServiceTest extends TestCase
{
    #[DataProvider('combinedFeePrices')]
    public function test_a_combined_fee_is_the_fixed_part_plus_a_percentage_of_the_ticket_price(float $price, float $expectedFee): void
    {
        $result = $this->service()->calculateTaxAndFeesForProduct($this->product([$this->serviceFee()]), $price);

        $this->assertSame($expectedFee, $result->feeTotal);
        $this->assertSame(0.0, $result->taxTotal);
    }

    public static function combinedFeePrices(): array
    {
        return [
            '100 kr' => [100.00, 5.00],
            '200 kr' => [200.00, 6.00],
            '350 kr' => [350.00, 7.50],
            '500 kr' => [500.00, 9.00],
            'rounds down at the third decimal' => [33.33, 4.33],
            'rounds up at the third decimal' => [166.67, 5.67],
            'rounds a half öre up' => [12.50, 4.13],
            'rounds 5.4999 to 5.50' => [149.99, 5.50],
        ];
    }

    public function test_the_combined_fee_is_rounded_per_ticket_and_then_multiplied_by_the_quantity(): void
    {
        $result = $this->service()->calculateTaxAndFeesForProduct($this->product([$this->serviceFee()]), 33.33, 3);

        $this->assertSame(12.99, $result->feeTotal);
        $this->assertCount(1, $result->rollUp['fees']);
        $this->assertSame('Serviceavgift', $result->rollUp['fees'][0]['name']);
        $this->assertSame(TaxCalculationType::FIXED_PLUS_PERCENTAGE->name, $result->rollUp['fees'][0]['type']);
        $this->assertSame(4.0, $result->rollUp['fees'][0]['fixed_amount']);
        $this->assertSame(1.0, $result->rollUp['fees'][0]['rate']);
        $this->assertSame(12.99, $result->rollUp['fees'][0]['value']);
    }

    public function test_the_percentage_part_never_applies_to_other_fees(): void
    {
        $tenPercent = $this->fee('Booking', TaxCalculationType::PERCENTAGE, 10);

        $result = $this->service()->calculateTaxAndFeesForProduct($this->product([$this->serviceFee(), $tenPercent]), 100.00);

        $this->assertSame(15.0, $result->feeTotal);
    }

    public function test_taxes_are_still_calculated_on_the_price_including_the_combined_fee(): void
    {
        $vat = (new TaxAndFeesDomainObject)
            ->setName('Moms')
            ->setType(TaxType::TAX->name)
            ->setCalculationType(TaxCalculationType::PERCENTAGE->name)
            ->setRate(25);

        $result = $this->service()->calculateTaxAndFeesForProduct($this->product([$this->serviceFee(), $vat]), 100.00);

        $this->assertSame(5.0, $result->feeTotal);
        $this->assertSame(26.25, $result->taxTotal);
    }

    public function test_inclusive_vat_is_carved_out_of_the_price_and_never_added(): void
    {
        $vat = (new TaxAndFeesDomainObject)
            ->setName('Moms')
            ->setType(TaxType::TAX->name)
            ->setCalculationType(TaxCalculationType::PERCENTAGE->name)
            ->setRate(25)
            ->setIsInclusive(true);

        $result = $this->service()->calculateTaxAndFeesForProduct($this->product([$this->serviceFee(), $vat]), 200.00, 2);

        // 4 kr + 1 % of 200 kr = 6 kr per ticket, two tickets.
        $this->assertSame(12.0, $result->feeTotal);
        $this->assertSame(0.0, $result->taxTotal);
        $this->assertSame(80.0, $result->inclusiveTaxTotal);

        $rolledVat = collect($result->rollUp['taxes'])->firstWhere('name', 'Moms');
        $this->assertTrue($rolledVat['inclusive']);
        $this->assertSame(80.0, $rolledVat['value']);
    }

    #[DataProvider('inheritedFeeVatProvider')]
    public function test_a_fee_inherits_the_tickets_included_vat_rate(float $rate, float $ticketVat, float $feeVat): void
    {
        $vat = (new TaxAndFeesDomainObject)
            ->setName('Moms')
            ->setType(TaxType::TAX->name)
            ->setCalculationType(TaxCalculationType::PERCENTAGE->name)
            ->setRate($rate)
            ->setIsInclusive(true);
        $fee = $this->serviceFee()->setInheritsTicketVat(true);

        // 200 kr ticket, fee 4 + 1 % = 6,00 kr, two tickets.
        $result = $this->service()->calculateTaxAndFeesForProduct($this->product([$fee, $vat]), 200.00, 2);

        $this->assertSame(12.0, $result->feeTotal);
        $this->assertSame(0.0, $result->taxTotal);
        $this->assertSame(round(($ticketVat + $feeVat) * 2, 2), round($result->inclusiveTaxTotal, 2));

        $moms = collect($result->rollUp['taxes'])->firstWhere('name', 'Moms');
        $this->assertTrue($moms['inclusive']);
        $this->assertSame(round(($ticketVat + $feeVat) * 2, 2), round($moms['value'], 2));
        $this->assertSame(round($feeVat * 2, 2), round($moms['fee_value'], 2));
    }

    public static function inheritedFeeVatProvider(): array
    {
        return [
            '6 % (concert rate)' => [6.0, 11.32, 0.34],
            '25 % (club entry)' => [25.0, 40.00, 1.20],
        ];
    }

    public function test_a_fee_that_does_not_inherit_carries_no_vat(): void
    {
        $vat = (new TaxAndFeesDomainObject)
            ->setName('Moms')
            ->setType(TaxType::TAX->name)
            ->setCalculationType(TaxCalculationType::PERCENTAGE->name)
            ->setRate(6)
            ->setIsInclusive(true);

        $result = $this->service()->calculateTaxAndFeesForProduct($this->product([$this->serviceFee(), $vat]), 200.00, 2);

        $moms = collect($result->rollUp['taxes'])->firstWhere('name', 'Moms');
        $this->assertSame(22.64, round($moms['value'], 2));
        $this->assertSame(0.0, round($moms['fee_value'], 2));
    }

    public function test_a_free_ticket_carries_no_combined_fee(): void
    {
        $result = $this->service()->calculateTaxAndFeesForProduct($this->product([$this->serviceFee()]), 0.00);

        $this->assertSame(0.0, $result->feeTotal);
        $this->assertSame(0.0, $result->rollUp['fees'][0]['value']);
    }

    public function test_fixed_only_and_percentage_only_fees_behave_exactly_as_before(): void
    {
        $fixed = $this->fee('Fixed', TaxCalculationType::FIXED, 2.5);
        $percentage = $this->fee('Percent', TaxCalculationType::PERCENTAGE, 10);

        $fixedResult = $this->service()->calculateTaxAndFeesForProduct($this->product([$fixed]), 33.33, 2);
        $percentageResult = $this->service()->calculateTaxAndFeesForProduct($this->product([$percentage]), 33.33, 2);

        $this->assertSame(5.0, $fixedResult->feeTotal);
        $this->assertEqualsWithDelta(6.666, $percentageResult->feeTotal, 0.0001);
        $this->assertNull($fixedResult->rollUp['fees'][0]['fixed_amount']);
    }

    private function service(): TaxAndFeeCalculationService
    {
        return new TaxAndFeeCalculationService(new TaxAndFeeRollupService);
    }

    private function serviceFee(): TaxAndFeesDomainObject
    {
        return $this->fee('Serviceavgift', TaxCalculationType::FIXED_PLUS_PERCENTAGE, 1)->setFixedAmount(4.00);
    }

    private function fee(string $name, TaxCalculationType $calculationType, float $rate): TaxAndFeesDomainObject
    {
        return (new TaxAndFeesDomainObject)
            ->setName($name)
            ->setType(TaxType::FEE->name)
            ->setCalculationType($calculationType->name)
            ->setRate($rate);
    }

    private function product(array $taxesAndFees): ProductDomainObject
    {
        return (new ProductDomainObject)->setId(1)->setTaxAndFees(collect($taxesAndFees));
    }
}
