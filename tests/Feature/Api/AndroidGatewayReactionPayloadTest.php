<?php

namespace Tests\Feature\Api;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndroidGatewayReactionPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_receive_sms_persists_reaction_payload_fields_in_meta(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $payload = [
            'device_id' => 'gw-device-1',
            'contact_number' => '+12185938880',
            'receiver_number' => '14157676262',
            'message' => 'Loved "hello world"',
            'timestamp' => 1712627738000,
            'type' => 'incoming',
            'message_type' => 'sms',
            'sim_slot' => 2,
            'sim_info' => ['slot' => 2, 'number' => '14157676262', 'carrier' => 'carrier-x'],
            'attachments' => [],
            'is_reaction' => true,
            'reaction_type' => 'love',
            'reaction_action' => 'add',
            'reaction_target' => 'hello world',
        ];

        $this->postJson('/api/sms/receive', $payload)->assertOk();

        $message = Message::query()->latest('id')->firstOrFail();
        $this->assertTrue((bool) data_get($message->meta, 'is_reaction'));
        $this->assertSame('love', data_get($message->meta, 'reaction_type'));
        $this->assertSame('add', data_get($message->meta, 'reaction_action'));
        $this->assertSame('hello world', data_get($message->meta, 'reaction_target'));
    }
}
