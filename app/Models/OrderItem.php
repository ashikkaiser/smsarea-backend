<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    public const PRODUCT_NUMBER = 'number';

    public const PRODUCT_DEVICE_SLOT = 'device_slot';

    public const PRODUCT_ESIM = 'esim';

    protected $fillable = [
        'order_id',
        'product_type',
        'product_id',
        'quantity',
        'unit_amount_minor',
        'line_amount_minor',
        'currency',
        'duration_days',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'line_amount_minor' => 'integer',
            'duration_days' => 'integer',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
