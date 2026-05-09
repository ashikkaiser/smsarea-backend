<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEsimCarrierPriceOverride extends Model
{
    protected $table = 'user_esim_carrier_price_overrides';

    protected $fillable = [
        'user_id',
        'esim_carrier_plan_id',
        'price_minor',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function carrierPlan(): BelongsTo
    {
        return $this->belongsTo(EsimCarrierPlan::class, 'esim_carrier_plan_id');
    }
}
