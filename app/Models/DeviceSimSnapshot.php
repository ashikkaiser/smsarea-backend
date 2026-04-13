<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSimSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'sim_slot',
        'phone_number',
        'carrier_name',
        'observed_at',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
