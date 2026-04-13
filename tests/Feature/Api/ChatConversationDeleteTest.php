<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatConversationDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_own_conversation(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_chat' => true,
        ]);
        $bearer = $user->createToken('test')->plainTextToken;

        $phone = PhoneNumber::query()->create([
            'phone_number' => '+12025550111',
            'status' => 'active',
        ]);
        $conversation = Conversation::query()->create([
            'phone_number_id' => $phone->id,
            'contact_number' => '+12025550199',
            'assigned_user_id' => $user->id,
            'status' => 'open',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$bearer}")
            ->deleteJson('/api/v1/chat/conversations/'.$conversation->id);

        $response->assertOk();
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    }

    public function test_user_cannot_delete_other_users_conversation(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_chat' => true,
        ]);
        $other = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_chat' => true,
        ]);
        $bearer = $user->createToken('test')->plainTextToken;

        $phone = PhoneNumber::query()->create([
            'phone_number' => '+12025550111',
            'status' => 'active',
        ]);
        $conversation = Conversation::query()->create([
            'phone_number_id' => $phone->id,
            'contact_number' => '+12025550199',
            'assigned_user_id' => $other->id,
            'status' => 'open',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$bearer}")
            ->deleteJson('/api/v1/chat/conversations/'.$conversation->id);

        $response->assertNotFound();
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }
}
