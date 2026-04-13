<?php

namespace Tests\Feature\Api;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AndroidGatewayReactionServerParseTest extends TestCase
{
    use RefreshDatabase;

    public function test_receive_sms_sets_reaction_meta_when_gateway_omits_flags(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $payload = [
            'device_id' => 'gw-device-1',
            'contact_number' => '+12185938880',
            'receiver_number' => '14157676262',
            'message' => 'Loved "hello"',
            'timestamp' => 1712627738000,
            'type' => 'incoming',
            'message_type' => 'sms',
            'sim_slot' => 2,
            'sim_info' => ['slot' => 2, 'number' => '14157676262', 'carrier' => 'carrier-x'],
            'attachments' => [],
        ];

        $this->postJson('/api/sms/receive', $payload)->assertOk();

        $message = Message::query()->latest('id')->firstOrFail();
        $this->assertTrue((bool) data_get($message->meta, 'is_reaction'));
        $this->assertSame('love', data_get($message->meta, 'reaction_type'));
        $this->assertSame('add', data_get($message->meta, 'reaction_action'));
        $this->assertSame('hello', data_get($message->meta, 'reaction_target'));
    }

    public function test_receive_sms_sets_reaction_meta_for_markdown_starred_target(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $payload = [
            'device_id' => 'gw-device-1',
            'contact_number' => '+12185938880',
            'receiver_number' => '14157676262',
            'message' => 'Loved **hello**',
            'timestamp' => 1712627739000,
            'type' => 'incoming',
            'message_type' => 'sms',
            'sim_slot' => 2,
            'sim_info' => ['slot' => 2, 'number' => '14157676262', 'carrier' => 'carrier-x'],
            'attachments' => [],
        ];

        $this->postJson('/api/sms/receive', $payload)->assertOk();

        $message = Message::query()->latest('id')->firstOrFail();
        $this->assertTrue((bool) data_get($message->meta, 'is_reaction'));
        $this->assertSame('hello', data_get($message->meta, 'reaction_target'));
    }
}
