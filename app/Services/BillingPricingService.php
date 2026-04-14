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

    public function deviceSlotUnitMinorForUser(User $user): int
    {
        $settings = BillingSetting::current();
        $override = UserPhonePrice::query()->where('user_id', $user->id)->first();

        return (int) ($override?->device_slot_price_minor ?? $settings->device_slot_price_minor);
    }

    public function esimUnitMinorForUser(User $user): int
    {
        $settings = BillingSetting::current();
        $override = UserPhonePrice::query()->where('user_id', $user->id)->first();

        return (int) ($override?->esim_price_minor ?? $settings->esim_price_minor);
    }

    /**
     * @return array{amount_minor: int, currency: string, duration_days: int}
     */
    public function deviceSlotPricingForUser(User $user, ?int $durationDays = null): array
    {
        $settings = BillingSetting::current();
        $duration = $durationDays ?? (int) $settings->default_duration_days;

        return [
            'amount_minor' => $this->deviceSlotUnitMinorForUser($user),
            'currency' => strtoupper((string) $settings->currency),
            'duration_days' => max(1, $duration),
        ];
    }

    /**
     * @return array{amount_minor: int, currency: string, duration_days: int}
     */
    public function esimPricingForUser(User $user, ?int $durationDays = null): array
    {
        $settings = BillingSetting::current();
        $duration = $durationDays ?? (int) $settings->default_duration_days;

        return [
            'amount_minor' => $this->esimUnitMinorForUser($user),
            'currency' => strtoupper((string) $settings->currency),
            'duration_days' => max(1, $duration),
        ];
    }
}
