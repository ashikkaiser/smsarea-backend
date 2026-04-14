<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_CONFIRMING = 'confirming';

    public const STATUS_PAID = 'paid';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'amount_minor',
        'currency',
        'status',
        'source',
        'provider',
        'provider_payment_id',
        'provider_pay_address',
        'provider_pay_currency',
        'provider_pay_amount',
        'meta',
        'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'provider_pay_amount' => 'decimal:12',
            'meta' => 'array',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
