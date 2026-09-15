<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Billing\DTO;

use Carbon\CarbonImmutable;
use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Support\Collection;

class MonthlyBillingSummaryDTO extends BaseDataObject
{
    /**
     * @param  Collection<int, OrganizerBillingLineDTO>  $lines
     */
    public function __construct(
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly string $currency,
        public readonly Collection $lines,
        public readonly float $grandTotal,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines->isEmpty();
    }

    public function monthLabel(): string
    {
        return $this->periodStart->locale('sv')->translatedFormat('F Y');
    }
}
