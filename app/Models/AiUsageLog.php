<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $fillable = [
        'user_id',
        'campaign_id',
        'source',
        'conversation_id',
        'message_id',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public const SOURCE_CAMPAIGN_INBOUND = 'campaign_inbound';

    public const SOURCE_ADMIN_PLAYGROUND = 'admin_playground';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
