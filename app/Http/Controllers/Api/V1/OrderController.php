<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Models\Order;
use App\Services\UniversalOrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UniversalOrderService $orders,
    ) {}

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
}
