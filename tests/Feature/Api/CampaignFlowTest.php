<?php

namespace Tests\Feature\Api;

use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_campaign_permission_can_create_campaign_and_step(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_campaign' => true,
        ]);
        $token = $user->createToken('campaign-test', ['user', 'campaign'])->plainTextToken;

        $campaignResponse = $this->withToken($token)->postJson('/api/v1/campaigns', [
            'name' => 'Launch A',
            'agent_name' => 'Agent Z',
            'entry_message_template' => '{hi|hello}',
        ]);

        $campaignResponse->assertCreated();
        $campaignId = $campaignResponse->json('data.id');

        $stepResponse = $this->withToken($token)->postJson("/api/v1/campaigns/{$campaignId}/steps", [
            'step_order' => 1,
            'step_type' => 'initial',
            'message_template' => 'Hello there',
            'delay_seconds' => 0,
        ]);

        $stepResponse->assertCreated();
        $this->assertDatabaseHas('campaign_steps', ['campaign_id' => $campaignId, 'step_order' => 1]);
    }

    public function test_campaign_index_includes_statistics_and_numbers_count(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_campaign' => true,
        ]);
        $token = $user->createToken('campaign-index', ['user', 'campaign'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/campaigns', [
            'name' => 'Stats board',
            'agent_name' => 'Agent Q',
            'settings' => [
                'statistics' => [
                    'sent' => 12,
                    'pending' => 3,
                    'total' => 20,
                    'delivered' => 10,
                    'failed' => 1,
                ],
            ],
        ])->assertCreated();

        $list = $this->withToken($token)->getJson('/api/v1/campaigns')->assertOk()->json('data.0');

        $this->assertSame(12, $list['statistics']['sent']);
        $this->assertSame(3, $list['statistics']['pending']);
        $this->assertSame(20, $list['statistics']['total']);
        $this->assertArrayHasKey('numbers_count', $list);
        $this->assertSame(0, $list['numbers_count']);
    }

    public function test_user_can_patch_campaign_status_and_delete_campaign(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_campaign' => true,
            'can_chat' => true,
        ]);
        $pn = PhoneNumber::create([
            'phone_number' => '+12025550999',
            'status' => 'active',
        ]);
        $user->assignedPhoneNumbers()->attach($pn->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $token = $user->createToken('campaign-actions', ['user', 'campaign'])->plainTextToken;

        $create = $this->withToken($token)->postJson('/api/v1/campaigns', [
            'name' => 'Lifecycle',
            'agent_name' => 'Bot',
            'phone_number_ids' => [$pn->id],
        ])->assertCreated();

        $id = $create->json('data.id');
        $this->assertSame('draft', $create->json('data.status'));

        $this->withToken($token)->putJson("/api/v1/campaigns/{$id}", [
            'name' => 'Lifecycle',
            'agent_name' => 'Bot',
            'entry_message_template' => 'Hello there',
            'phone_number_ids' => [$pn->id],
            'steps' => [
                ['step_order' => 1, 'step_type' => 'reply', 'message_template' => 'Reply text'],
                ['step_order' => 2, 'step_type' => 'followup', 'message_template' => 'Follow-up text'],
            ],
        ])->assertOk();

        $this->withToken($token)
            ->patchJson("/api/v1/campaigns/{$id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->withToken($token)
            ->patchJson("/api/v1/campaigns/{$id}/status", ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->withToken($token)
            ->deleteJson("/api/v1/campaigns/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('campaigns', ['id' => $id]);
    }

    public function test_campaign_syncs_phone_number_ids_on_create_and_update(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_campaign' => true,
            'can_chat' => true,
        ]);
        $pn1 = PhoneNumber::create([
            'phone_number' => '+12025550101',
            'status' => 'active',
        ]);
        $pn2 = PhoneNumber::create([
            'phone_number' => '+12025550102',
            'status' => 'active',
        ]);
        $user->assignedPhoneNumbers()->attach($pn1->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);
        $user->assignedPhoneNumbers()->attach($pn2->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $token = $user->createToken('campaign-phones', ['user', 'campaign'])->plainTextToken;

        $res = $this->withToken($token)->postJson('/api/v1/campaigns', [
            'name' => 'Multi-line',
            'phone_number_ids' => [$pn1->id],
        ])->assertCreated();

        $campaignId = $res->json('data.id');
        $this->assertDatabaseHas('campaign_phone_number', [
            'campaign_id' => $campaignId,
            'phone_number_id' => $pn1->id,
        ]);
        $this->assertDatabaseMissing('campaign_phone_number', [
            'campaign_id' => $campaignId,
            'phone_number_id' => $pn2->id,
        ]);

        $this->withToken($token)->putJson("/api/v1/campaigns/{$campaignId}", [
            'name' => 'Multi-line',
            'phone_number_ids' => [$pn1->id, $pn2->id],
            'steps' => [
                ['step_order' => 1, 'step_type' => 'reply', 'message_template' => 'Hello'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('campaign_phone_number', [
            'campaign_id' => $campaignId,
            'phone_number_id' => $pn2->id,
        ]);
    }

    public function test_phone_number_cannot_be_linked_to_two_campaigns(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_campaign' => true,
            'can_chat' => true,
        ]);
        $pn = PhoneNumber::create([
            'phone_number' => '+12025550888',
            'status' => 'active',
        ]);
        $user->assignedPhoneNumbers()->attach($pn->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $token = $user->createToken('campaign-exclusive', ['user', 'campaign'])->plainTextToken;

        $a = $this->withToken($token)->postJson('/api/v1/campaigns', [
            'name' => 'Campaign A',
            'phone_number_ids' => [$pn->id],
        ])->assertCreated()->json('data.id');

        $b = $this->withToken($token)->postJson('/api/v1/campaigns', [
            'name' => 'Campaign B',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->putJson("/api/v1/campaigns/{$b}", [
            'name' => 'Campaign B',
            'entry_message_template' => 'Hi',
            'phone_number_ids' => [$pn->id],
            'steps' => [
                ['step_order' => 1, 'step_type' => 'reply', 'message_template' => 'R'],
                ['step_order' => 2, 'step_type' => 'followup', 'message_template' => 'F'],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('campaign_phone_number', [
            'campaign_id' => $b,
            'phone_number_id' => $pn->id,
        ]);

        $this->withToken($token)->deleteJson("/api/v1/campaigns/{$a}")->assertOk();

        $this->withToken($token)->putJson("/api/v1/campaigns/{$b}", [
            'name' => 'Campaign B',
            'entry_message_template' => 'Hi',
            'phone_number_ids' => [$pn->id],
            'steps' => [
                ['step_order' => 1, 'step_type' => 'reply', 'message_template' => 'R'],
                ['step_order' => 2, 'step_type' => 'followup', 'message_template' => 'F'],
            ],
        ])->assertOk();
    }
}
