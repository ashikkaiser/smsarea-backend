<?php

namespace Tests\Feature\Api;

use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPhoneNumberAssignTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_accepts_formatted_phone_number_and_user_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $assignee = User::factory()->create(['role' => 'user', 'status' => 'active']);

        $phoneNumber = PhoneNumber::create([
            'phone_number' => '+12025550199',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/v1/admin/phone-numbers/assign', [
                'phone_number' => '(202) 555-0199',
                'user_id' => $assignee->id,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('phone_number_user', [
            'phone_number_id' => $phoneNumber->id,
            'user_id' => $assignee->id,
            'status' => 'active',
        ]);
    }

    public function test_assign_rejects_unknown_phone_number(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $assignee = User::factory()->create(['role' => 'user', 'status' => 'active']);

        $response = $this->withToken($token)
            ->postJson('/api/v1/admin/phone-numbers/assign', [
                'phone_number' => '+19999999999',
                'user_id' => $assignee->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['phone_number']);
    }

    public function test_assign_rejects_admin_user_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $anotherAdmin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        PhoneNumber::create([
            'phone_number' => '+12025550188',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/v1/admin/phone-numbers/assign', [
                'phone_number' => '+12025550188',
                'user_id' => $anotherAdmin->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }
}
