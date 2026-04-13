<?php

namespace Tests\Feature\Api;

use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_user_can_send_chat_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'can_chat' => true]);
        $user = User::factory()->create(['role' => 'user', 'status' => 'active', 'can_chat' => true]);
        $token = $user->createToken('chat-test', ['user', 'chat'])->plainTextToken;

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550155',
            'status' => 'active',
            'sim_slot' => 1,
        ]);
        $phoneNumber->users()->syncWithoutDetaching([
            $user->id => ['assigned_by' => $admin->id, 'assigned_at' => now(), 'status' => 'active'],
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/chat/send', [
            'phone_number_id' => $phoneNumber->id,
            'contact_number' => '+12025550199',
            'message' => 'Hello',
            'message_type' => 'sms',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('messages', ['direction' => 'outbound', 'body' => 'Hello']);
    }
}
