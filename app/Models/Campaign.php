<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'agent_name',
        'status',
        'entry_message_template',
        'ai_inbound_enabled',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'ai_inbound_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class);
    }

    public function phoneNumbers(): BelongsToMany
    {
        return $this->belongsToMany(PhoneNumber::class, 'campaign_phone_number')
            ->withPivot(['id', 'assigned_at', 'assigned_by'])
            ->withTimestamps();
    }
}
