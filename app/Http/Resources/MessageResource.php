<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'message_type' => $this->message_type,
            'body' => $this->body,
            'attachments' => $this->attachments,
            'status' => $this->status,
            'occurred_at' => $this->occurred_at,
            'meta' => [
                'is_reaction' => (bool) data_get($this->meta, 'is_reaction', false),
                'reaction_type' => data_get($this->meta, 'reaction_type'),
                'reaction_action' => data_get($this->meta, 'reaction_action'),
                'reaction_target' => data_get($this->meta, 'reaction_target'),
            ],
        ];
    }
}
