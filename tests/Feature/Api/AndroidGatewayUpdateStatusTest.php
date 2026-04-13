<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Device;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AndroidGatewayUpdateStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_status_matches_message_primary_key_from_gateway(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $device = Device::query()->create([
            'device_uid' => 'gw-uid',
            'device_token' => (string) Str::uuid(),
            'status' => 'online',
        ]);
        $phone = PhoneNumber::query()->create([
            'device_id' => $device->id,
            'sim_slot' => 1,
            'phone_number' => '+12025550100',
            'status' => 'active',
        ]);
        $conversation = Conversation::query()->create([
            'phone_number_id' => $phone->id,
            'contact_number' => '+12025550199',
            'assigned_user_id' => $user->id,
            'status' => 'open',
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'phone_number_id' => $phone->id,
            'direction' => 'outbound',
            'body' => 'x',
            'device_message_id' => null,
            'status' => 'queued',
            'occurred_at' => now(),
        ]);

        $response = $this->postJson('/api/sms/update-status', [
            'device_id' => 'gw-uid',
            'message_id' => $message->id,
            'status' => 'failed',
        ]);

        $response->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame('failed', $message->fresh()->status);
    }

    public function test_update_status_still_matches_device_message_id_column(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $phone = PhoneNumber::query()->create([
            'device_id' => null,
            'sim_slot' => 1,
            'phone_number' => '+12025550100',
            'status' => 'active',
        ]);
        $conversation = Conversation::query()->create([
            'phone_number_id' => $phone->id,
            'contact_number' => '+12025550199',
            'assigned_user_id' => $user->id,
            'status' => 'open',
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'phone_number_id' => $phone->id,
            'direction' => 'outbound',
            'body' => 'x',
            'device_message_id' => 999,
            'status' => 'queued',
            'occurred_at' => now(),
        ]);

        $this->postJson('/api/sms/update-status', [
            'device_id' => 'any',
            'message_id' => 999,
            'status' => 'sent',
        ])->assertOk()->assertJsonPath('updated', 1);

        $this->assertSame('sent', $message->fresh()->status);
    }
}
