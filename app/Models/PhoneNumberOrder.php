<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneNumberOrder extends Model
{
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_CONFIRMING = 'confirming';

    public const STATUS_PAID = 'paid';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_WAIVED = 'waived';

    public const SOURCE_USER_SELF = 'user_self';

    public const SOURCE_ADMIN_ASSIGN = 'admin_assign';

    protected $fillable = [
        'user_id',
        'phone_number_id',
        'duration_days',
        'amount_minor',
        'currency',
        'status',
        'source',
        'assigned_by_user_id',
        'provider',
        'provider_payment_id',
        'provider_pay_address',
        'provider_pay_currency',
        'provider_pay_amount',
        'phone_number_purchase_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'amount_minor' => 'integer',
            'provider_pay_amount' => 'decimal:12',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(PhoneNumberPurchase::class, 'phone_number_purchase_id');
    }
}
