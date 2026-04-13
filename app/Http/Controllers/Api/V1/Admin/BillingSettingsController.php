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
            $row ? $row->only(['price_minor_per_period', 'currency', 'duration_days']) : null,
            'User phone price fetched.',
        );
    }

    public function updateUserPrice(UpdateUserPhonePriceRequest $request, User $user): JsonResponse
    {
        if ($user->role !== 'user') {
            return $this->failure('User pricing applies only to user-role accounts.', 422);
        }
        $data = $request->validated();
        $allNull = ($data['price_minor_per_period'] ?? null) === null
            && ($data['currency'] ?? null) === null
            && ($data['duration_days'] ?? null) === null;

        if ($allNull) {
            UserPhonePrice::query()->where('user_id', $user->id)->delete();

            return $this->success(null, 'User phone price override cleared.');
        }

        $row = UserPhonePrice::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'price_minor_per_period' => $data['price_minor_per_period'] ?? null,
                'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
                'duration_days' => $data['duration_days'] ?? null,
            ],
        );

        return $this->success($row->only(['price_minor_per_period', 'currency', 'duration_days']), 'User phone price saved.');
    }
}
