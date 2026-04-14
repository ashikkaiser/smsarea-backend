<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Models\BillingSetting;
use App\Models\EsimInventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\BillingPricingService;
use App\Services\PhoneNumberOrderService;
use App\Services\UniversalOrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UniversalOrderService $orders,
        private readonly BillingPricingService $pricing,
        private readonly PhoneNumberOrderService $numberOrders,
    ) {}

    public function catalog(): JsonResponse
    {
        $settings = BillingSetting::current();
        $numbers = $this->numberOrders->availableNumbersQuery()->limit(200)->get()->map(
            static fn ($n): array => [
                'id' => $n->id,
                'phone_number' => $n->phone_number,
                'carrier_name' => $n->carrier_name,
                'status' => $n->status,
            ]
        );
        $esims = EsimInventory::query()
            ->where('status', EsimInventory::STATUS_AVAILABLE)
            ->limit(200)
            ->get()
            ->map(static fn (EsimInventory $e): array => [
                'id' => $e->id,
                'masked_phone_number' => $e->maskedPhoneNumber(),
                'zip_code' => $e->zip_code,
                'area_code' => $e->area_code,
                'status' => $e->status,
            ]);

        return $this->success([
            'number_products' => $numbers,
            'esim_products' => $esims,
            'device_slot_product' => ['product_type' => OrderItem::PRODUCT_DEVICE_SLOT],
            'pricing' => [
                'number' => $this->pricing->quoteForUser(request()->user()),
                'device_slot' => [
                    'amount_minor' => (int) $settings->device_slot_price_minor,
                    'currency' => strtoupper((string) $settings->currency),
                    'duration_days' => (int) $settings->default_duration_days,
                ],
                'esim' => [
                    'amount_minor' => (int) $settings->esim_price_minor,
                    'currency' => strtoupper((string) $settings->currency),
                    'duration_days' => (int) $settings->default_duration_days,
                ],
            ],
        ], 'Universal catalog fetched.');
    }

    public function pricingPreview(): JsonResponse
    {
        $validated = request()->validate([
            'product_type' => ['required', 'string', 'in:number,device_slot,esim'],
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ]);
        $settings = BillingSetting::current();
        $durationDays = isset($validated['duration_days']) ? (int) $validated['duration_days'] : null;

        if ($validated['product_type'] === OrderItem::PRODUCT_NUMBER) {
            return $this->success(
                $this->pricing->quoteForUser(request()->user(), $durationDays),
                'Pricing preview.'
            );
        }

        if ($validated['product_type'] === OrderItem::PRODUCT_DEVICE_SLOT) {
            return $this->success([
                'amount_minor' => (int) $settings->device_slot_price_minor,
                'currency' => strtoupper((string) $settings->currency),
                'duration_days' => $durationDays ?? (int) $settings->default_duration_days,
            ], 'Pricing preview.');
        }

        return $this->success([
            'amount_minor' => (int) $settings->esim_price_minor,
            'currency' => strtoupper((string) $settings->currency),
            'duration_days' => $durationDays ?? (int) $settings->default_duration_days,
        ], 'Pricing preview.');
    }

    public function index(): JsonResponse
    {
        $rows = Order::query()
            ->where('user_id', request()->user()->id)
            ->with('items')
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->success($rows, 'Orders fetched.');
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $result = $this->orders->createCheckoutOrder($request->user(), $request->validated());
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage(), 422);
        }

        return $this->success([
            'order' => $result['order'],
            'payment' => $result['payment'],
        ], 'Checkout started.', 201);
    }

    public function show(Order $order): JsonResponse
    {
        if ((int) $order->user_id !== (int) request()->user()->id) {
            return $this->failure('Not found.', 404);
        }

        return $this->success($order->load('items'), 'Order fetched.');
    }
}
