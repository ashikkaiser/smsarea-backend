<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\PurchaseNumberRequest;
use App\Http\Resources\PhoneNumberPurchaseResource;
use App\Models\BillingSetting;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberPurchase;
use App\Services\NumberPurchaseService;
use Illuminate\Http\JsonResponse;

class NumberPurchaseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NumberPurchaseService $numberPurchaseService) {}

    public function myNumbers(): JsonResponse
    {
        $purchases = PhoneNumberPurchase::query()
            ->where('user_id', request()->user()->id)
            ->where('status', '!=', 'revoked')
            ->with('phoneNumber')
            ->latest('purchase_date')
            ->get();

        $settings = BillingSetting::current();

        return $this->success(
            PhoneNumberPurchaseResource::collection($purchases),
            'Purchased numbers fetched.',
            200,
            [
                'self_checkout_enabled' => (bool) $settings->self_checkout_enabled,
                'nowpayments_configured' => $settings->nowpayments_api_key !== null && $settings->nowpayments_api_key !== '',
            ],
        );
    }

    public function purchase(PurchaseNumberRequest $request): JsonResponse
    {
        $phoneNumber = PhoneNumber::query()->findOrFail($request->validated('phone_number_id'));
        $this->authorize('purchase', $phoneNumber);
        $purchase = $this->numberPurchaseService->purchase($request->user(), $phoneNumber, $request->validated());

        return $this->success(new PhoneNumberPurchaseResource($purchase->load('phoneNumber')), 'Number purchased.', 201);
    }

    public function renew(PhoneNumber $phoneNumber): JsonResponse
    {
        $this->authorize('renew', $phoneNumber);
        $purchase = $this->numberPurchaseService->purchase(request()->user(), $phoneNumber, [
            'amount_minor' => 0,
            'currency' => 'USD',
            'duration_days' => 30,
            'auto_renew' => false,
        ]);

        return $this->success(new PhoneNumberPurchaseResource($purchase->load('phoneNumber')), 'Number renewed.');
    }
}
