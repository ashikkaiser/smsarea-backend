<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\EsimInventory;
use App\Models\UserEsim;
use Illuminate\Http\JsonResponse;

class EsimController extends Controller
{
    use ApiResponse;

    public function catalog(): JsonResponse
    {
        $validated = request()->validate([
            'zip_code' => ['nullable', 'string', 'max:16'],
            'area_code' => ['nullable', 'string', 'max:16'],
        ]);

        $query = EsimInventory::query()
            ->where('status', EsimInventory::STATUS_AVAILABLE)
            ->latest('id');

        if (! empty($validated['zip_code'])) {
            $query->where('zip_code', $validated['zip_code']);
        }
        if (! empty($validated['area_code'])) {
            $query->where('area_code', $validated['area_code']);
        }

        $rows = $query->limit(200)->get()->map(function (EsimInventory $row): array {
            return [
                'id' => $row->id,
                'masked_phone_number' => $row->maskedPhoneNumber(),
                'zip_code' => $row->zip_code,
                'area_code' => $row->area_code,
                'status' => $row->status,
            ];
        });

        return $this->success($rows, 'eSIM catalog fetched.');
    }

    public function myEsims(): JsonResponse
    {
        $rows = UserEsim::query()
            ->where('user_id', request()->user()->id)
            ->with('esim')
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->success($rows, 'My eSIMs fetched.');
    }

    public function reveal(UserEsim $userEsim): JsonResponse
    {
        if ((int) $userEsim->user_id !== (int) request()->user()->id) {
            return $this->failure('Not found.', 404);
        }

        if ($userEsim->revealed_at === null) {
            $userEsim->update(['revealed_at' => now()]);
        }

        return $this->success($userEsim->fresh('esim'), 'eSIM revealed.');
    }
}
