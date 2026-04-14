<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserDeviceEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDeviceClaimCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_issue_claim_code_and_register_device_with_it(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_device' => true,
        ]);
        UserDeviceEntitlement::query()->create([
            'user_id' => $user->id,
            'slots_purchased' => 1,
            'slots_used' => 0,
            'status' => 'active',
        ]);

        $web = $user->createToken('w', ['user'])->plainTextToken;
        $issue = $this->withToken($web)->postJson('/api/v1/devices/claim-code');
        $issue->assertOk();
        $raw = (string) $issue->json('data.code_raw');
        $this->assertSame(8, strlen($raw));

        $reg = $this->postJson('/api/device/register', [
            'token' => $raw,
            'device_uid' => 'claim-test-uid',
            'sim_info' => [['slot' => 0, 'number' => null, 'carrier' => null]],
        ]);
        $reg->assertOk();
        $reg->assertJsonStructure(['device_uid', 'device_token']);

        $this->assertDatabaseHas('devices', [
            'device_uid' => 'claim-test-uid',
            'owner_user_id' => $user->id,
        ]);
    }

    public function test_claim_code_is_single_use(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active', 'can_device' => true]);
        UserDeviceEntitlement::query()->create([
            'user_id' => $user->id,
            'slots_purchased' => 2,
            'slots_used' => 0,
            'status' => 'active',
        ]);
        $web = $user->createToken('w', ['user'])->plainTextToken;
        $raw = (string) $this->withToken($web)->postJson('/api/v1/devices/claim-code')->json('data.code_raw');

        $sim = [['slot' => 0, 'number' => null, 'carrier' => null]];
        $this->postJson('/api/device/register', [
            'token' => $raw,
            'device_uid' => 'first-uid',
            'sim_info' => $sim,
        ])->assertOk();

        $this->postJson('/api/device/register', [
            'token' => $raw,
            'device_uid' => 'second-uid',
            'sim_info' => $sim,
        ])->assertStatus(422);
    }

    public function test_issue_claim_code_requires_device_permission(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'can_device' => false,
        ]);
        $web = $user->createToken('w', ['user'])->plainTextToken;

        $this->withToken($web)->postJson('/api/v1/devices/claim-code')->assertForbidden();
    }

    public function test_issue_claim_code_rejected_without_available_slot(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active', 'can_device' => true]);
        $web = $user->createToken('w', ['user'])->plainTextToken;

        $this->withToken($web)->postJson('/api/v1/devices/claim-code')->assertStatus(422);

        UserDeviceEntitlement::query()->create([
            'user_id' => $user->id,
            'slots_purchased' => 1,
            'slots_used' => 1,
            'status' => 'active',
        ]);

        $this->withToken($web)->postJson('/api/v1/devices/claim-code')->assertStatus(422);
    }

    public function test_issue_claim_code_rejected_when_valid_until_passed(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active', 'can_device' => true]);
        UserDeviceEntitlement::query()->create([
            'user_id' => $user->id,
            'slots_purchased' => 1,
            'slots_used' => 0,
            'status' => 'active',
            'valid_until' => now()->subDay(),
        ]);
        $web = $user->createToken('w', ['user'])->plainTextToken;

        $this->withToken($web)->postJson('/api/v1/devices/claim-code')->assertStatus(422);
    }
}
