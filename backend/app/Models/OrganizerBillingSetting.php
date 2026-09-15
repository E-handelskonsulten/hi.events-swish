<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizerBillingSetting extends BaseModel
{
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'sms_enabled' => 'boolean',
            'platform_fee_per_ticket' => 'float',
            'sms_fee_per_message' => 'float',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}
