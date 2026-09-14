<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizerSwishSetting extends BaseModel
{
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'enabled' => 'boolean',
            'key_passphrase' => 'encrypted',
            'last_verified_at' => 'datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}
