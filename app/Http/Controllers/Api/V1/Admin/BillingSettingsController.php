<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBillingSettingsRequest;
use App\Http\Requests\Admin\UpdateUserPhonePriceRequest;
use App\Models\BillingSetting;
use App\Models\User;
use App\Models\UserPhonePrice;
use Illuminate\Http\JsonResponse;

class BillingSettingsController extends Controller
{
    use ApiResponse;

    public function show(): JsonResponse
    {
        return $this->success(BillingSetting::current()->toAdminArray(), 'Billing settings fetched.');
    }

    public function update(UpdateBillingSettingsRequest $request): JsonResponse
    {
        $row = BillingSetting::current();
        $data = $request->validated();
        $row->fill($data);
        $row->save();

        return $this->success($row->fresh()->toAdminArray(), 'Billing settings updated.');
    }

    public function showUserPrice(User $user): JsonResponse
    {
        if ($user->role !== 'user') {
            return $this->failure('User pricing applies only to user-role accounts.', 422);
        }
        $row = UserPhonePrice::query()->where('user_id', $user->id)->first();

        return $this->success(
            [
                'price_minor_per_period' => $row?->price_minor_per_period,
                'currency' => $row?->currency,
                'duration_days' => $row?->duration_days,
                'device_slot_price_minor' => $row?->device_slot_price_minor,
                'esim_price_minor' => $row?->esim_price_minor,
            ],
            'User product pricing fetched.',
        );
    }

    public function updateUserPrice(UpdateUserPhonePriceRequest $request, User $user): JsonResponse
    {
        if ($user->role !== 'user') {
            return $this->failure('User pricing applies only to user-role accounts.', 422);
        }
        $data = $request->validated();

        $phonePrice = $data['price_minor_per_period'] ?? null;
        $phoneCurrency = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;
        $phoneDuration = $data['duration_days'] ?? null;

        $phoneAllNull = $phonePrice === null && $phoneCurrency === null && $phoneDuration === null;
        $phoneAllSet = $phonePrice !== null && $phoneCurrency !== null && $phoneDuration !== null;

        if (! $phoneAllNull && ! $phoneAllSet) {
            return $this->failure(
                'Provide phone price, currency, and billing period together, or omit all three to use catalog defaults for numbers.',
                422,
            );
        }

        $deviceSlotMinor = $data['device_slot_price_minor'] ?? null;
        $esimMinor = $data['esim_price_minor'] ?? null;

        if ($phoneAllNull && $deviceSlotMinor === null && $esimMinor === null) {
            UserPhonePrice::query()->where('user_id', $user->id)->delete();

            return $this->success(null, 'All product pricing overrides cleared.');
        }

        $row = UserPhonePrice::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'price_minor_per_period' => $phoneAllSet ? $phonePrice : null,
                'currency' => $phoneAllSet ? $phoneCurrency : null,
                'duration_days' => $phoneAllSet ? $phoneDuration : null,
                'device_slot_price_minor' => $deviceSlotMinor,
                'esim_price_minor' => $esimMinor,
            ],
        );

        return $this->success(
            [
                'price_minor_per_period' => $row->price_minor_per_period,
                'currency' => $row->currency,
                'duration_days' => $row->duration_days,
                'device_slot_price_minor' => $row->device_slot_price_minor,
                'esim_price_minor' => $row->esim_price_minor,
            ],
            'User product pricing saved.',
        );
    }
}
