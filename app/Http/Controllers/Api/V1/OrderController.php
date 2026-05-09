<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Models\BillingSetting;
use App\Models\EsimCarrierPlan;
use App\Models\EsimInventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\BillingPricingService;
use App\Services\PhoneNumberOrderService;
use App\Services\UniversalOrderService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $user = request()->user();
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
            ->with('carrierPlan')
            ->limit(200)
            ->get()
            ->map(function (EsimInventory $e) use ($user): array {
                $row = [
                    'id' => $e->id,
                    'masked_phone_number' => $e->maskedPhoneNumber(),
                    'zip_code' => $e->zip_code,
                    'area_code' => $e->area_code,
                    'status' => $e->status,
                ];
                try {
                    $q = $this->pricing->esimQuoteForUserAndInventory($user, $e);
                    $row['carrier'] = [
                        'id' => $q['carrier_plan_id'],
                        'slug' => $q['carrier_slug'],
                        'name' => $q['carrier_name'],
                    ];
                    $row['pricing'] = [
                        'amount_minor' => $q['amount_minor'],
                        'currency' => $q['currency'],
                        'duration_days' => $q['duration_days'],
                    ];
                } catch (\Throwable) {
                    $row['carrier'] = null;
                    $row['pricing'] = null;
                }

                return $row;
            });

        $data = [
            'number_products' => $numbers,
            'esim_products' => $esims,
            'pricing' => [
                'number' => $this->pricing->quoteForUser($user),
                'esim_carriers' => $this->pricing->esimCarrierPlansPricingForUser($user),
            ],
        ];

        if ($user->can_device) {
            $data['device_slot_product'] = ['product_type' => OrderItem::PRODUCT_DEVICE_SLOT];
            $data['pricing']['device_slot'] = $this->pricing->deviceSlotPricingForUser($user);
        }

        return $this->success($data, 'Universal catalog fetched.');
    }

    public function pricingPreview(): JsonResponse
    {
        $validated = request()->validate([
            'product_type' => ['required', 'string', 'in:number,device_slot,esim'],
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'esim_inventory_id' => ['sometimes', 'integer', 'exists:esim_inventories,id'],
            'esim_carrier_plan_id' => ['sometimes', 'integer', 'exists:esim_carrier_plans,id'],
        ]);
        $durationDays = isset($validated['duration_days']) ? (int) $validated['duration_days'] : null;

        if ($validated['product_type'] === OrderItem::PRODUCT_NUMBER) {
            return $this->success(
                $this->pricing->quoteForUser(request()->user(), $durationDays),
                'Pricing preview.'
            );
        }

        if ($validated['product_type'] === OrderItem::PRODUCT_DEVICE_SLOT) {
            if (! request()->user()->can_device) {
                return $this->failure('Device workspace is disabled for this account.', 403);
            }

            return $this->success(
                $this->pricing->deviceSlotPricingForUser(request()->user(), $durationDays),
                'Pricing preview.',
            );
        }

        $user = request()->user();
        if (! empty($validated['esim_inventory_id'])) {
            $esim = EsimInventory::query()->with('carrierPlan')->findOrFail((int) $validated['esim_inventory_id']);

            return $this->success(
                $this->pricing->esimQuoteForUserAndInventory($user, $esim),
                'Pricing preview.',
            );
        }

        if (! empty($validated['esim_carrier_plan_id'])) {
            $plan = EsimCarrierPlan::query()->where('is_active', true)->findOrFail((int) $validated['esim_carrier_plan_id']);
            $settings = BillingSetting::current();
            $dur = $plan->duration_days ?? $durationDays ?? (int) $settings->default_duration_days;

            return $this->success([
                'amount_minor' => $this->pricing->esimPriceMinorForUserAndPlan($user, $plan),
                'currency' => strtoupper((string) $settings->currency),
                'duration_days' => max(1, (int) $dur),
                'carrier_plan_id' => (int) $plan->id,
                'carrier_slug' => (string) $plan->slug,
                'carrier_name' => (string) $plan->name,
            ], 'Pricing preview.');
        }

        return $this->success(
            ['carriers' => $this->pricing->esimCarrierPlansPricingForUser($user)],
            'eSIM pricing is per carrier. Pass esim_inventory_id or esim_carrier_plan_id for a line item quote, or use carriers list.',
        );
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
        } catch (HttpExceptionInterface $e) {
            throw $e;
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
