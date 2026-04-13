<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number_id' => $this->phone_number_id,
            'contact_number' => $this->contact_number,
            'assigned_user_id' => $this->assigned_user_id,
            'last_message_at' => $this->last_message_at,
            'status' => $this->status,
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
