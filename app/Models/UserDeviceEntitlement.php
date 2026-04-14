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

    /**
     * Status shown to clients: active rows past valid_until are treated as expired.
     */
    public function effectiveStatus(): string
    {
        if ($this->status !== 'active') {
            return (string) $this->status;
        }

        $until = $this->valid_until;
        if ($until !== null && $until->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    public function isUsable(): bool
    {
        return $this->effectiveStatus() === 'active';
    }
}
