<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\EsimInventory;
use App\Models\UserEsim;
use App\Services\BillingPricingService;
use Illuminate\Http\JsonResponse;

class EsimController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BillingPricingService $pricing,
    ) {}

    public function catalog(): JsonResponse
    {
        $validated = request()->validate([
            'zip_code' => ['nullable', 'string', 'max:16'],
            'area_code' => ['nullable', 'string', 'max:16'],
        ]);

        $query = EsimInventory::query()
            ->where('status', EsimInventory::STATUS_AVAILABLE)
            ->with('carrierPlan')
            ->latest('id');

        if (! empty($validated['zip_code'])) {
            $query->where('zip_code', $validated['zip_code']);
        }
        if (! empty($validated['area_code'])) {
            $query->where('area_code', $validated['area_code']);
        }

        $user = request()->user();
        $rows = $query->limit(200)->get()->map(function (EsimInventory $row) use ($user): array {
            $base = [
                'id' => $row->id,
                'masked_phone_number' => $row->maskedPhoneNumber(),
                'zip_code' => $row->zip_code,
                'area_code' => $row->area_code,
                'status' => $row->status,
            ];
            try {
                $q = $this->pricing->esimQuoteForUserAndInventory($user, $row);
                $base['carrier'] = [
                    'id' => $q['carrier_plan_id'],
                    'slug' => $q['carrier_slug'],
                    'name' => $q['carrier_name'],
                ];
                $base['pricing'] = [
                    'amount_minor' => $q['amount_minor'],
                    'currency' => $q['currency'],
                    'duration_days' => $q['duration_days'],
                ];
            } catch (\Throwable) {
                $base['carrier'] = null;
                $base['pricing'] = null;
            }

            return $base;
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
