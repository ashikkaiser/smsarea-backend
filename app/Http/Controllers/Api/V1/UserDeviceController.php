<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\UserDeviceEntitlement;
use Illuminate\Http\JsonResponse;

class UserDeviceController extends Controller
{
    use ApiResponse;

    public function myDevices(): JsonResponse
    {
        $user = request()->user();

        $entitlement = UserDeviceEntitlement::query()
            ->where('user_id', $user->id)
            ->first();

        $devices = Device::query()
            ->where('owner_user_id', $user->id)
            ->with(['phoneNumbers' => function ($query): void {
                $query->orderBy('sim_slot')->orderBy('id')
                    ->select(['id', 'device_id', 'sim_slot', 'phone_number', 'carrier_name', 'status']);
            }])
            ->orderByDesc('id')
            ->get()
            ->map(function (Device $device): array {
                return [
                    'id' => $device->id,
                    'device_uid' => $device->device_uid,
                    'custom_name' => $device->custom_name,
                    'model' => $device->model,
                    'os' => $device->os,
                    'status' => $device->status,
                    'last_seen_at' => $device->last_seen_at,
                    'claimed_at' => $device->claimed_at,
                    'phone_numbers' => $device->phoneNumbers->map(static fn ($p): array => [
                        'id' => $p->id,
                        'sim_slot' => $p->sim_slot,
                        'phone_number' => $p->phone_number,
                        'carrier_name' => $p->carrier_name,
                        'status' => $p->status,
                    ])->values()->all(),
                ];
            });

        return $this->success([
            'user_id' => $user->id,
            'entitlement' => $entitlement ? [
                'slots_purchased' => (int) $entitlement->slots_purchased,
                'slots_used' => (int) $entitlement->slots_used,
                'slots_available' => $entitlement->availableSlots(),
                'status' => $entitlement->status,
                'valid_until' => $entitlement->valid_until,
            ] : null,
            'devices' => $devices,
        ], 'Your devices fetched.');
    }
}
