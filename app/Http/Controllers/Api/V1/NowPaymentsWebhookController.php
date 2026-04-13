<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\PhoneNumberOrder;
use App\Services\NowPaymentsClient;
use App\Services\PhoneNumberOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NowPaymentsWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        NowPaymentsClient $nowPayments,
        PhoneNumberOrderService $orderService,
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
        if ($paymentId !== null && $paymentId !== '') {
            $order = PhoneNumberOrder::query()
                ->where('provider_payment_id', (string) $paymentId)
                ->first();
        }
        if ($order === null && $orderId !== null && $orderId !== '') {
            $order = PhoneNumberOrder::query()->find((int) $orderId);
        }

        if ($order === null) {
            Log::warning('NOWPayments IPN: order not found', [
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]);

            return response()->json(['ok' => true]);
        }

        $order->update([
            'meta' => array_merge($order->meta ?? [], [
                'last_ipn' => [
                    'payment_status' => $paymentStatus,
                    'at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        if (in_array($paymentStatus, ['finished', 'confirmed'], true)) {
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

        if (in_array($paymentStatus, ['failed', 'expired', 'refunded'], true)) {
            $order->update(['status' => PhoneNumberOrder::STATUS_CANCELLED]);
        }

        return response()->json(['ok' => true]);
    }
}
