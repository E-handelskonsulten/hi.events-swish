<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SwishMassRefundRun extends BaseModel
{
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'total_amount' => 'float',
            'succeeded_amount' => 'float',
            'notify_buyers' => 'boolean',
            'cancel_orders' => 'boolean',
            'summary' => 'array',
            'last_activity_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SwishMassRefundItem::class, 'run_id');
    }
}
