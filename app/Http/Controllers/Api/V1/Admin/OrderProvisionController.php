<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UniversalOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderProvisionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UniversalOrderService $orders,
    ) {}

    public function provisionDeviceSlot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ]);

        $target = User::query()->findOrFail((int) $data['user_id']);
        if ($target->role !== 'user') {
            return $this->failure('Target account must be a user-role account.', 422);
        }

        try {
            $order = $this->orders->provisionOrderByAdmin($request->user(), $target, [
                'product_type' => 'device_slot',
                'quantity' => (int) ($data['quantity'] ?? 1),
                'duration_days' => isset($data['duration_days']) ? (int) $data['duration_days'] : null,
            ]);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 422);
        }

        return $this->success($order, 'Device slots provisioned for user.');
    }

    public function provisionEsim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'esim_inventory_id' => ['required', 'integer', 'exists:esim_inventories,id'],
        ]);

        $target = User::query()->findOrFail((int) $data['user_id']);
        if ($target->role !== 'user') {
            return $this->failure('Target account must be a user-role account.', 422);
        }

        try {
            $order = $this->orders->provisionOrderByAdmin($request->user(), $target, [
                'product_type' => 'esim',
                'esim_inventory_id' => (int) $data['esim_inventory_id'],
            ]);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 422);
        }

        return $this->success($order, 'eSIM provisioned for user.');
    }
}
