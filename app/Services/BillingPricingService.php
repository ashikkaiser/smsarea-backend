<?php

namespace App\Services;

use App\Models\BillingSetting;
use App\Models\EsimCarrierPlan;
use App\Models\EsimInventory;
use App\Models\User;
use App\Models\UserEsimCarrierPriceOverride;
use App\Models\UserPhonePrice;
use RuntimeException;

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

    /**
     * Per-carrier price: user override row, else carrier plan catalog price.
     */
    public function esimPriceMinorForUserAndPlan(User $user, EsimCarrierPlan $plan): int
    {
        $row = UserEsimCarrierPriceOverride::query()
            ->where('user_id', $user->id)
            ->where('esim_carrier_plan_id', $plan->id)
            ->first();
        if ($row) {
            return max(0, (int) $row->price_minor);
        }

        return max(0, (int) $plan->price_minor);
    }

    /**
     * Active carriers with resolved prices (for universal catalog / checkout UI).
     *
     * @return list<array{id: int, slug: string, name: string, amount_minor: int, duration_days: int}>
     */
    public function esimCarrierPlansPricingForUser(User $user): array
    {
        $settings = BillingSetting::current();
        $defaultDuration = (int) $settings->default_duration_days;

        return EsimCarrierPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (EsimCarrierPlan $plan) use ($user, $defaultDuration): array {
                return [
                    'id' => (int) $plan->id,
                    'slug' => (string) $plan->slug,
                    'name' => (string) $plan->name,
                    'amount_minor' => $this->esimPriceMinorForUserAndPlan($user, $plan),
                    'duration_days' => max(1, (int) ($plan->duration_days ?? $defaultDuration)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{amount_minor: int, currency: string, duration_days: int, carrier_plan_id: int, carrier_slug: string, carrier_name: string}
     */
    public function esimQuoteForUserAndInventory(User $user, EsimInventory $esim): array
    {
        $settings = BillingSetting::current();
        $plan = $esim->relationLoaded('carrierPlan') ? $esim->carrierPlan : $esim->carrierPlan()->first();
        if (! $plan) {
            throw new RuntimeException('This eSIM inventory row has no carrier. Assign a carrier in Admin → eSIM inventory.');
        }
        if (! $plan->is_active) {
            throw new RuntimeException('This eSIM carrier plan is not available for purchase.');
        }

        $duration = $plan->duration_days ?? (int) $settings->default_duration_days;

        return [
            'amount_minor' => $this->esimPriceMinorForUserAndPlan($user, $plan),
            'currency' => strtoupper((string) $settings->currency),
            'duration_days' => max(1, (int) $duration),
            'carrier_plan_id' => (int) $plan->id,
            'carrier_slug' => (string) $plan->slug,
            'carrier_name' => (string) $plan->name,
        ];
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
}
