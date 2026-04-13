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

class AdminDebugMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_fetch_message_by_device_message_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $assignee = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $bearer = $admin->createToken('test')->plainTextToken;

        $device = Device::query()->create([
            'device_uid' => 'gw-test',
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
            'assigned_user_id' => $assignee->id,
            'status' => 'open',
        ]);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'phone_number_id' => $phone->id,
            'direction' => 'outbound',
            'body' => 'test body',
            'device_message_id' => 17,
            'device_id' => $device->id,
            'status' => 'failed',
            'occurred_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$bearer}")
            ->getJson('/api/v1/admin/debug/messages/by-device-message-id/17');

        $response->assertOk()->assertJsonPath('data.device_message_id', 17)->assertJsonPath('data.status', 'failed');
    }
}
