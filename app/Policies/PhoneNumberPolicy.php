<?php

namespace App\Policies;

use App\Models\PhoneNumber;
use App\Models\PhoneNumberPurchase;
use App\Models\User;

class PhoneNumberPolicy
{
    public function useForChat(User $user, PhoneNumber $phoneNumber): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $phoneNumber->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    public function purchase(User $user, PhoneNumber $phoneNumber): bool
    {
        if ($user->role !== 'admin') {
            return false;
        }

        return $phoneNumber->status !== 'expired';
    }

    public function renew(User $user, PhoneNumber $phoneNumber): bool
    {
        if ($user->role === 'admin') {
            return $phoneNumber->status !== 'expired';
        }

        return PhoneNumberPurchase::query()
            ->where('user_id', $user->id)
            ->where('phone_number_id', $phoneNumber->id)
            ->where('status', 'active')
            ->exists();
    }
}
