<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'phone_number_id',
        'direction',
        'message_type',
        'body',
        'attachments',
        'provider_message_id',
        'device_message_id',
        'device_id',
        'sim_slot',
        'status',
        'occurred_at',
        'meta',
    ];

    protected $casts = [
        'attachments' => 'array',
        'occurred_at' => 'datetime',
        'meta' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }
}
