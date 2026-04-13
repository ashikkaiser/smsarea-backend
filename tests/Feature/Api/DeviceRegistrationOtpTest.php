<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceRegistrationOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_issue_pairing_otp_and_device_register_consumes_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $bearer = $admin->createToken('test')->plainTextToken;

        $issue = $this->withHeader('Authorization', "Bearer {$bearer}")
            ->postJson('/api/v1/admin/devices/registration-otp', ['ttl_minutes' => 15]);

        $issue->assertOk();
        $otp = $issue->json('data.otp');
        $this->assertIsString($otp);
        $this->assertMatchesRegularExpression('/^[23456789A-Z]{4}-[23456789A-Z]{4}$/', $otp);

        $plain = str_replace('-', '', $otp);

        $register = $this->postJson('/api/device/register', [
            'token' => $plain,
            'device_uid' => 'SERIAL-OTP-1',
            'model' => 'Pixel',
            'os' => 'Android 15',
            'sim_info' => json_encode([['slot' => 1, 'number' => '+12025550100', 'carrier' => 'Carrier']]),
        ]);

        $register->assertOk()->assertJsonStructure(['device_uid', 'ws_url', 'device_token']);

        $again = $this->postJson('/api/device/register', [
            'token' => $otp,
            'device_uid' => 'SERIAL-OTP-2',
            'model' => 'Pixel',
            'os' => 'Android 15',
            'sim_info' => json_encode([['slot' => 1, 'number' => '+12025550101', 'carrier' => 'Carrier']]),
        ]);

        $again->assertStatus(422);
    }
}
