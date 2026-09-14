<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SwishMassRefundItem extends BaseModel
{
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'amount' => 'float',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SwishMassRefundRun::class, 'run_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function swish_refund(): BelongsTo
    {
        return $this->belongsTo(SwishRefund::class);
    }
}
