<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function register(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'user',
            'status' => 'pending',
            'can_chat' => false,
            'can_campaign' => false,
            'can_device' => false,
        ]);
    }

    public function issueToken(User $user, string $name = 'web-token'): string
    {
        $abilities = ['user'];
        if ($user->role === 'admin') {
            $abilities[] = 'admin';
        }
        if ($user->can_chat) {
            $abilities[] = 'chat';
        }
        if ($user->can_campaign) {
            $abilities[] = 'campaign';
        }
        if ($user->can_device) {
            $abilities[] = 'device';
        }

        return $user->createToken($name, $abilities)->plainTextToken;
    }
}
