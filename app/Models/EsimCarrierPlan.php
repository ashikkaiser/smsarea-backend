<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EsimCarrierPlan extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'price_minor',
        'duration_days',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'duration_days' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(EsimInventory::class, 'esim_carrier_plan_id');
    }

    public function userPriceOverrides(): HasMany
    {
        return $this->hasMany(UserEsimCarrierPriceOverride::class, 'esim_carrier_plan_id');
    }
}
