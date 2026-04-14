<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDeviceEntitlement extends Model
{
    protected $fillable = [
        'user_id',
        'slots_purchased',
        'slots_used',
        'status',
        'valid_until',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'slots_purchased' => 'integer',
            'slots_used' => 'integer',
            'valid_until' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function availableSlots(): int
    {
        return max(0, (int) $this->slots_purchased - (int) $this->slots_used);
    }
}
