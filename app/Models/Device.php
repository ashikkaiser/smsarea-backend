<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    protected $fillable = [
        'device_uid',
        'device_token',
        'owner_user_id',
        'claimed_at',
        'custom_name',
        'model',
        'os',
        'last_seen_at',
        'status',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function snapshots(): HasMany
    {
        return $this->hasMany(DeviceSimSnapshot::class);
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
