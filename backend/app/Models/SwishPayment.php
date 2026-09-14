<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SwishPayment extends BaseModel
{
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'amount' => 'float',
            'callback_payload' => 'array',
            'date_paid' => 'datetime',
            'last_polled_at' => 'datetime',
            'poll_attempts' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
