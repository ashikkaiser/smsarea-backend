<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\Order;
use App\Models\PhoneNumberOrder;
use App\Services\NowPaymentsClient;
use App\Services\PhoneNumberOrderService;
use App\Services\UniversalOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NowPaymentsWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        NowPaymentsClient $nowPayments,
        PhoneNumberOrderService $orderService,
        UniversalOrderService $universalOrders,
    ): JsonResponse {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        $sig = (string) $request->header('x-nowpayments-sig', '');

        try {
            $settings = BillingSetting::current();
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'message' => 'Billing not initialized'], 503);
        }

        if (! $nowPayments->verifyIpnSignature($payload, $sig, $settings)) {
            return response()->json(['ok' => false, 'message' => 'Invalid signature'], 401);
        }

        $paymentId = $payload['payment_id'] ?? null;
        $orderId = $payload['order_id'] ?? null;
        $paymentStatus = isset($payload['payment_status']) ? (string) $payload['payment_status'] : '';

        $order = null;
        $universalOrder = null;
        if ($paymentId !== null && $paymentId !== '') {
            $order = PhoneNumberOrder::query()
                ->where('provider_payment_id', (string) $paymentId)
                ->first();
            $universalOrder = Order::query()
                ->where('provider_payment_id', (string) $paymentId)
                ->first();
        }
        if ($order === null && $orderId !== null && $orderId !== '') {
            $order = PhoneNumberOrder::query()->find((int) $orderId);
        }
        if ($universalOrder === null && $orderId !== null && $orderId !== '') {
            $universalOrder = Order::query()->find((int) $orderId);
        }

        if ($order === null && $universalOrder === null) {
            Log::warning('NOWPayments IPN: order not found', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]);

            return response()->json(['ok' => true]);
        }

        if ($order !== null) {
            $order->update([
                'meta' => array_merge($order->meta ?? [], [
                    'last_ipn' => [
                        'payment_status' => $paymentStatus,
                        'at' => now()->toIso8601String(),
                    ],
                ]),
            ]);
        }
        if ($universalOrder !== null) {
            $universalOrder->update([
                'meta' => array_merge($universalOrder->meta ?? [], [
                    'last_ipn' => [
                        'payment_status' => $paymentStatus,
                        'at' => now()->toIso8601String(),
                    ],
                ]),
            ]);
        }

        if (in_array($paymentStatus, ['finished', 'confirmed'], true)) {
            if ($order !== null) {
                $orderService->markOrderPaidIfAwaiting($order);
                try {
                    $orderService->fulfillPaidOrder($order->fresh());
                } catch (\Throwable $e) {
                    Log::error('NOWPayments fulfill failed', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            if ($universalOrder !== null) {
                $universalOrders->markOrderPaidIfAwaiting($universalOrder);
                try {
                    $universalOrders->fulfillPaidOrder($universalOrder->fresh());
                } catch (\Throwable $e) {
                    Log::error('NOWPayments universal fulfill failed', [
                        'order_id' => $universalOrder->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (in_array($paymentStatus, ['failed', 'expired', 'refunded'], true)) {
            if ($order !== null) {
                $order->update(['status' => PhoneNumberOrder::STATUS_CANCELLED]);
            }
            if ($universalOrder !== null) {
                $universalOrder->update(['status' => Order::STATUS_CANCELLED]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
