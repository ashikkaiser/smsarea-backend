<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneNumberPurchase extends Model
{
    protected $fillable = [
        'phone_number_id',
        'user_id',
        'purchase_date',
        'expiry_date',
        'amount_minor',
        'currency',
        'status',
        'auto_renew',
        'renewed_from_purchase_id',
        'meta',
    ];

    protected $casts = [
        'purchase_date' => 'datetime',
        'expiry_date' => 'datetime',
        'auto_renew' => 'boolean',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }
}
