<?php

namespace Tests\Feature\Api;

use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPhoneNumberUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_by_status_and_carrier(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        PhoneNumber::create([
            'phone_number' => '+12025550101',
            'carrier_name' => 'Acme Mobile',
            'status' => 'active',
            'sim_slot' => 0,
        ]);
        PhoneNumber::create([
            'phone_number' => '+12025550102',
            'carrier_name' => 'Beta Wireless',
            'status' => 'inactive',
            'sim_slot' => 1,
        ]);

        $active = $this->withToken($token)
            ->getJson('/api/v1/admin/phone-numbers/assignments?status=active');
        $active->assertOk();
        $this->assertCount(1, $active->json('data.data'));
        $this->assertSame('active', $active->json('data.data.0.status'));

        $carrier = $this->withToken($token)
            ->getJson('/api/v1/admin/phone-numbers/assignments?'.http_build_query([
                'carrier' => 'Beta Wireless',
            ]));
        $carrier->assertOk();
        $this->assertCount(1, $carrier->json('data.data'));
        $this->assertSame('Beta Wireless', (string) $carrier->json('data.data.0.carrier_name'));
    }

    public function test_carrier_names_endpoint_lists_distinct_carrier_names(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        PhoneNumber::create([
            'phone_number' => '+12025550110',
            'carrier_name' => 'Gamma Net',
            'status' => 'active',
            'sim_slot' => 0,
        ]);
        PhoneNumber::create([
            'phone_number' => '+12025550111',
            'carrier_name' => 'Gamma Net',
            'status' => 'active',
            'sim_slot' => 1,
        ]);
        PhoneNumber::create([
            'phone_number' => '+12025550112',
            'carrier_name' => 'Alpha Link',
            'status' => 'active',
            'sim_slot' => 2,
        ]);
        PhoneNumber::create([
            'phone_number' => '+12025550113',
            'carrier_name' => ' gamma net ',
            'status' => 'active',
            'sim_slot' => 3,
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/phone-numbers/carriers');

        $response->assertOk();
        $names = $response->json('data');
        $this->assertIsArray($names);
        $this->assertSame(['Alpha Link', 'Gamma Net'], $names);
    }

    public function test_index_carrier_filter_matches_trimmed_carrier_names(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        PhoneNumber::create([
            'phone_number' => '+12025550120',
            'carrier_name' => '  Spaced Carrier  ',
            'status' => 'active',
            'sim_slot' => 0,
        ]);
        PhoneNumber::create([
            'phone_number' => '+12025550121',
            'carrier_name' => 'Other',
            'status' => 'active',
            'sim_slot' => 1,
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/phone-numbers/assignments?'.http_build_query([
                'carrier' => 'Spaced Carrier',
            ]));
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('+12025550120', $response->json('data.data.0.phone_number'));
    }

    public function test_index_filters_by_assigned_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $assignee = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $withAssign = PhoneNumber::create([
            'phone_number' => '+12025550201',
            'status' => 'active',
            'sim_slot' => 0,
        ]);
        $withAssign->users()->attach($assignee->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        PhoneNumber::create([
            'phone_number' => '+12025550202',
            'status' => 'active',
            'sim_slot' => 1,
        ]);

        $unassigned = $this->withToken($token)
            ->getJson('/api/v1/admin/phone-numbers/assignments?assigned=unassigned');
        $unassigned->assertOk();
        $this->assertCount(1, $unassigned->json('data.data'));
        $this->assertSame('+12025550202', $unassigned->json('data.data.0.phone_number'));

        $assigned = $this->withToken($token)
            ->getJson('/api/v1/admin/phone-numbers/assignments?assigned=assigned');
        $assigned->assertOk();
        $this->assertCount(1, $assigned->json('data.data'));
        $this->assertSame('+12025550201', $assigned->json('data.data.0.phone_number'));
    }

    public function test_index_filters_by_assigned_user_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $userA = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $userB = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $phoneA = PhoneNumber::create([
            'phone_number' => '+12025550301',
            'status' => 'active',
            'sim_slot' => 0,
        ]);
        $phoneA->users()->attach($userA->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $phoneB = PhoneNumber::create([
            'phone_number' => '+12025550302',
            'status' => 'active',
            'sim_slot' => 1,
        ]);
        $phoneB->users()->attach($userB->id, [
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $forA = $this->withToken($token)
            ->getJson('/api/v1/admin/phone-numbers/assignments?user_id='.$userA->id);
        $forA->assertOk();
        $this->assertCount(1, $forA->json('data.data'));
        $this->assertSame($phoneA->id, $forA->json('data.data.0.id'));
    }

    public function test_index_rejects_admin_user_id_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/phone-numbers/assignments?user_id='.$admin->id);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    public function test_admin_can_patch_phone_number_dates_and_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $phone = PhoneNumber::create([
            'phone_number' => '+12025550155',
            'carrier_name' => 'Old Carrier',
            'status' => 'inactive',
            'sim_slot' => 0,
        ]);

        $response = $this->withToken($token)
            ->patchJson("/api/v1/admin/phone-numbers/{$phone->id}", [
                'status' => 'active',
                'carrier_name' => 'New Carrier',
                'purchase_date' => '2026-01-15',
                'expiry_date' => '2027-06-30',
                'last_renewed_at' => '2026-04-01T12:00:00Z',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.carrier_name', 'New Carrier');
        $response->assertJsonPath('data.expiry_date', fn ($v) => $v !== null);

        $phone->refresh();
        $this->assertSame('active', $phone->status);
        $this->assertSame('New Carrier', $phone->carrier_name);
        $this->assertNotNull($phone->expiry_date);
    }

    public function test_non_admin_cannot_update_phone_number(): void
    {
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $token = $user->createToken('u', ['user'])->plainTextToken;

        $phone = PhoneNumber::create([
            'phone_number' => '+12025550166',
            'status' => 'active',
            'sim_slot' => 0,
        ]);

        $response = $this->withToken($token)
            ->patchJson("/api/v1/admin/phone-numbers/{$phone->id}", [
                'status' => 'expired',
            ]);

        $response->assertStatus(403);
        $this->assertSame('active', $phone->refresh()->status);
    }
}
