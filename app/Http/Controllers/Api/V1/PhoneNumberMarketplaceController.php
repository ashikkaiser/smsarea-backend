<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Numbers\StorePhoneNumberOrderRequest;
use App\Http\Resources\PhoneNumberCatalogResource;
use App\Http\Resources\PhoneNumberOrderResource;
use App\Models\BillingSetting;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberOrder;
use App\Services\BillingPricingService;
use App\Services\PhoneNumberOrderService;
use Illuminate\Http\JsonResponse;

class PhoneNumberMarketplaceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PhoneNumberOrderService $orderService,
        private readonly BillingPricingService $pricing,
    ) {}

    public function catalog(): JsonResponse
    {
        $user = request()->user();
        $settings = BillingSetting::current();
        $numbers = $this->orderService->availableNumbersQuery()->get();
        $quote = $this->pricing->quoteForUser($user);

        return $this->success([
            'numbers' => PhoneNumberCatalogResource::collection($numbers),
            'your_quote' => $quote,
            'self_checkout_enabled' => (bool) $settings->self_checkout_enabled,
            'pay_currency' => $settings->nowpayments_pay_currency,
            'nowpayments_configured' => $settings->nowpayments_api_key !== null && $settings->nowpayments_api_key !== '',
        ], 'Catalog fetched.');
    }

    public function pricingPreview(): JsonResponse
    {
        $validated = request()->validate([
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ]);
        $duration = isset($validated['duration_days']) ? (int) $validated['duration_days'] : null;
        $quote = $this->pricing->quoteForUser(request()->user(), $duration);

        return $this->success($quote, 'Pricing preview.');
    }

    public function myOrders(): JsonResponse
    {
        $rows = PhoneNumberOrder::query()
            ->where('user_id', request()->user()->id)
            ->with('phoneNumber')
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->success(PhoneNumberOrderResource::collection($rows), 'Orders fetched.');
    }

    public function store(StorePhoneNumberOrderRequest $request): JsonResponse
    {
        $phoneNumber = PhoneNumber::query()->findOrFail($request->validated('phone_number_id'));
        $duration = $request->validated('duration_days') ?? null;

        try {
            $result = $this->orderService->createCheckoutOrder(
                $request->user(),
                $phoneNumber,
                $duration !== null ? (int) $duration : null,
            );
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 422);
        }

        return $this->success([
            'order' => new PhoneNumberOrderResource($result['order']->load('phoneNumber')),
            'payment' => $result['payment'],
        ], 'Checkout started.', 201);
    }
}
