<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPhonePrice extends Model
{
    protected $fillable = [
        'user_id',
        'price_minor_per_period',
        'currency',
        'duration_days',
    ];

    protected function casts(): array
    {
        return [
            'price_minor_per_period' => 'integer',
            'duration_days' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
