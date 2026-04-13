<?php

namespace Tests\Feature\Api;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndroidGatewayReceiveSmsDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_receive_sms_ignores_duplicate_payload_with_same_timestamp(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $payload = [
            'device_id' => 'gw-device-1',
            'contact_number' => '+12185938880',
            'receiver_number' => '14157676262',
            'message' => 'hello duplicate check',
            'timestamp' => 1712627738000,
            'type' => 'incoming',
            'message_type' => 'sms',
            'sim_slot' => 2,
            'sim_info' => ['slot' => 2, 'number' => '14157676262', 'carrier' => 'carrier-x'],
            'attachments' => [],
        ];

        $first = $this->postJson('/api/sms/receive', $payload);
        $first->assertOk()->assertJsonMissing(['duplicate' => true]);

        $second = $this->postJson('/api/sms/receive', $payload);
        $second->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Message::query()->count());
    }
}
