<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RbacGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@smsarea.com'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('9745DHUg77@'),
                'role' => 'admin',
                'status' => 'active',
                'can_chat' => true,
                'can_campaign' => true,
                'can_device' => true,
            ],
        );

        $user = User::query()->updateOrCreate(
            ['email' => 'user@smsarea.com'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('9745DHUg77@'),
                'role' => 'user',
                'status' => 'active',
                'can_chat' => true,
                'can_campaign' => true,
                'can_device' => true,
            ],
        );
    }
}
