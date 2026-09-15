<?php

declare(strict_types=1);

namespace HiEvents\Models;

class BillingSummaryRun extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'period_start' => 'date',
            'sent_at' => 'datetime',
            'grand_total' => 'float',
        ];
    }
}
