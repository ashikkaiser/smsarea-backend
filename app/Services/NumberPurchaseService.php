<?php

namespace App\Services;

use App\Models\PhoneNumber;
use App\Models\PhoneNumberPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NumberPurchaseService
{
    public function purchase(User $user, PhoneNumber $phoneNumber, array $data): PhoneNumberPurchase
    {
        return DB::transaction(function () use ($user, $phoneNumber, $data) {
            $now = now();
            $expiry = $now->copy()->addDays((int) ($data['duration_days'] ?? 30));

            $purchase = PhoneNumberPurchase::create([
                'phone_number_id' => $phoneNumber->id,
                'user_id' => $user->id,
                'purchase_date' => $now,
                'expiry_date' => $expiry,
                'amount_minor' => $data['amount_minor'],
                'currency' => $data['currency'],
                'status' => 'active',
                'auto_renew' => (bool) ($data['auto_renew'] ?? false),
            ]);

            $phoneNumber->update([
                'purchase_date' => $now,
                'expiry_date' => $expiry,
                'last_renewed_at' => $now,
                'status' => 'active',
            ]);

            return $purchase;
        });
    }
}
