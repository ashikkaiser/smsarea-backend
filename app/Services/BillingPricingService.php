<?php

namespace App\Services;

use App\Models\BillingSetting;
use App\Models\User;
use App\Models\UserPhonePrice;

class BillingPricingService
{
    /**
     * @return array{amount_minor: int, currency: string, duration_days: int}
     */
    public function quoteForUser(User $user, ?int $durationDays = null): array
    {
        $settings = BillingSetting::current();
        $duration = $durationDays ?? (int) $settings->default_duration_days;
        $duration = max(1, $duration);

        $override = UserPhonePrice::query()->where('user_id', $user->id)->first();

        $baseMinor = $override?->price_minor_per_period ?? (int) $settings->default_price_minor;
        $currency = $override?->currency ?? strtoupper((string) $settings->currency);
        $baseDuration = $override?->duration_days ?? (int) $settings->default_duration_days;
        $baseDuration = max(1, $baseDuration);

        $amount = (int) max(0, round($baseMinor * ($duration / $baseDuration)));

        return [
            'amount_minor' => $amount,
            'currency' => $currency,
            'duration_days' => $duration,
        ];
    }
}
