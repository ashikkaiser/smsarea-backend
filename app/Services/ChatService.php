<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;

class ChatService
{
    public function getOrCreateConversation(User $user, PhoneNumber $phoneNumber, string $contactNumber): Conversation
    {
        return Conversation::firstOrCreate(
            [
                'phone_number_id' => $phoneNumber->id,
                'contact_number' => $contactNumber,
            ],
            [
                'assigned_user_id' => $user->id,
                'status' => 'open',
            ],
        );
    }

    public function addOutboundMessage(Conversation $conversation, array $data): Message
    {
        $message = $conversation->messages()->create([
            'phone_number_id' => $conversation->phone_number_id,
            'direction' => 'outbound',
            'message_type' => $data['message_type'] ?? 'sms',
            'body' => $data['message'],
            'attachments' => $data['attachments'] ?? [],
            'status' => 'queued',
            'occurred_at' => now(),
            'meta' => ['to' => $data['contact_number']],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $message;
    }
}
