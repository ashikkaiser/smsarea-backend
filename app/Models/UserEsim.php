<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEsim extends Model
{
    protected $fillable = [
        'user_id',
        'esim_inventory_id',
        'order_id',
        'status',
        'delivered_at',
        'revealed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'revealed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function esim(): BelongsTo
    {
        return $this->belongsTo(EsimInventory::class, 'esim_inventory_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
