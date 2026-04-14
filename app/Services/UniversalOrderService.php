<?php

namespace App\Services;

use App\Mail\EsimPurchaseDeliveredMail;
use App\Models\BillingSetting;
use App\Models\EsimInventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\UserDeviceEntitlement;
use App\Models\UserEsim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class UniversalOrderService
{
    public function __construct(
        private readonly BillingPricingService $pricing,
        private readonly NowPaymentsClient $nowPayments,
        private readonly NumberPurchaseService $numberPurchaseService,
        private readonly PhoneNumberService $phoneNumberService,
        private readonly PhoneNumberOrderService $phoneNumberOrderService,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{order: Order, payment: array<string, mixed>}
     */
    public function createCheckoutOrder(User $user, array $payload): array
    {
        $settings = BillingSetting::current();
        if (! $settings->self_checkout_enabled) {
            throw new RuntimeException('Self-checkout is disabled.');
        }

        $type = (string) ($payload['product_type'] ?? '');
        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $currency = strtoupper((string) $settings->currency);
        $durationDays = isset($payload['duration_days']) ? (int) $payload['duration_days'] : null;
        $productId = null;
        $unitAmountMinor = 0;
        $meta = [];

        if ($type === OrderItem::PRODUCT_NUMBER) {
            $productId = (int) ($payload['phone_number_id'] ?? 0);
            $phoneNumber = PhoneNumber::query()->findOrFail($productId);
            if (! $this->phoneNumberOrderService->availableNumbersQuery()->whereKey($phoneNumber->id)->exists()) {
                throw new RuntimeException('This number is not available for purchase.');
            }
            $quote = $this->pricing->quoteForUser($user, $durationDays);
            $unitAmountMinor = (int) $quote['amount_minor'];
            $currency = strtoupper((string) $quote['currency']);
            $durationDays = (int) $quote['duration_days'];
            $quantity = 1;
            $meta = ['phone_number' => $phoneNumber->phone_number];
        } elseif ($type === OrderItem::PRODUCT_DEVICE_SLOT) {
            $unitAmountMinor = (int) $settings->device_slot_price_minor;
            $durationDays ??= (int) $settings->default_duration_days;
        } elseif ($type === OrderItem::PRODUCT_ESIM) {
            $productId = (int) ($payload['esim_inventory_id'] ?? 0);
            $esim = EsimInventory::query()
                ->whereKey($productId)
                ->where('status', EsimInventory::STATUS_AVAILABLE)
                ->first();
            if (! $esim) {
                throw new RuntimeException('The selected eSIM is not available.');
            }
            $unitAmountMinor = (int) $settings->esim_price_minor;
            $durationDays ??= (int) $settings->default_duration_days;
            $quantity = 1;
            $meta = [
                'zip_code' => $esim->zip_code,
                'area_code' => $esim->area_code,
                'masked_phone_number' => $esim->maskedPhoneNumber(),
            ];
        } else {
            throw new RuntimeException('Unsupported product type.');
        }

        $lineAmount = $unitAmountMinor * $quantity;

        return DB::transaction(function () use ($user, $settings, $type, $productId, $quantity, $unitAmountMinor, $lineAmount, $currency, $durationDays, $meta) {
            $order = Order::query()->create([
                'user_id' => $user->id,
                'amount_minor' => $lineAmount,
                'currency' => $currency,
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'source' => 'user_self',
                'provider' => 'nowpayments',
                'meta' => [],
            ]);

            $order->items()->create([
                'product_type' => $type,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_amount_minor' => $unitAmountMinor,
                'line_amount_minor' => $lineAmount,
                'currency' => $currency,
                'duration_days' => $durationDays,
                'meta' => $meta,
            ]);

            $appUrl = rtrim((string) config('app.url'), '/');
            $successUrl = $appUrl.$settings->checkout_success_path;
            $cancelUrl = $appUrl.$settings->checkout_cancel_path;
            $ipnUrl = $appUrl.'/api/v1/webhooks/nowpayments';

            $priceMajor = $lineAmount / 100;
            $payment = $this->nowPayments->createPayment($settings, [
                'price_amount' => round($priceMajor, 2),
                'price_currency' => strtolower($currency),
                'pay_currency' => strtolower((string) $settings->nowpayments_pay_currency),
                'order_id' => (string) $order->id,
                'order_description' => 'Order #'.$order->id,
                'ipn_callback_url' => $ipnUrl,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ]);

            $order->update([
                'provider_payment_id' => isset($payment['payment_id']) ? (string) $payment['payment_id'] : null,
                'provider_pay_address' => isset($payment['pay_address']) ? (string) $payment['pay_address'] : null,
                'provider_pay_currency' => isset($payment['pay_currency']) ? (string) $payment['pay_currency'] : null,
                'provider_pay_amount' => isset($payment['pay_amount']) ? $payment['pay_amount'] : null,
                'meta' => ['provider_create_response' => $payment],
            ]);

            return ['order' => $order->fresh('items'), 'payment' => $payment];
        });
    }

    public function fulfillPaidOrder(Order $order): void
    {
        if ($order->status === Order::STATUS_FULFILLED) {
            return;
        }

        DB::transaction(function () use ($order): void {
            $order->refresh();
            if ($order->status === Order::STATUS_FULFILLED) {
                return;
            }

            $order->loadMissing('user', 'items');
            foreach ($order->items as $item) {
                if ($item->product_type === OrderItem::PRODUCT_NUMBER) {
                    $this->fulfillNumberItem($order->user, $item);
                } elseif ($item->product_type === OrderItem::PRODUCT_DEVICE_SLOT) {
                    $this->fulfillDeviceSlotItem($order->user, $item);
                } elseif ($item->product_type === OrderItem::PRODUCT_ESIM) {
                    $this->fulfillEsimItem($order, $order->user, $item);
                }
            }

            $order->update([
                'status' => Order::STATUS_FULFILLED,
                'fulfilled_at' => now(),
            ]);
        });
    }

    public function markOrderPaidIfAwaiting(Order $order): void
    {
        if (in_array($order->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_CONFIRMING], true)) {
            $order->update(['status' => Order::STATUS_PAID]);
        }
    }

    private function fulfillNumberItem(User $user, OrderItem $item): void
    {
        if (! $item->product_id) {
            throw new RuntimeException('Missing phone number product id.');
        }
        $phoneNumber = PhoneNumber::query()->findOrFail((int) $item->product_id);
        $purchase = $this->numberPurchaseService->purchase($user, $phoneNumber, [
            'amount_minor' => $item->line_amount_minor,
            'currency' => $item->currency,
            'duration_days' => $item->duration_days ?? 30,
            'auto_renew' => false,
        ]);
        $this->phoneNumberService->assignToUser($phoneNumber, $user, $user);
        $item->update([
            'meta' => array_merge($item->meta ?? [], ['phone_number_purchase_id' => $purchase->id]),
        ]);
    }

    private function fulfillDeviceSlotItem(User $user, OrderItem $item): void
    {
        $entitlement = UserDeviceEntitlement::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['slots_purchased' => 0, 'slots_used' => 0, 'status' => 'active']
        );

        $entitlement->forceFill([
            'slots_purchased' => (int) $entitlement->slots_purchased + (int) $item->quantity,
            'valid_until' => now()->addDays((int) ($item->duration_days ?? 30)),
            'status' => 'active',
        ])->save();
    }

    private function fulfillEsimItem(Order $order, User $user, OrderItem $item): void
    {
        if (! $item->product_id) {
            throw new RuntimeException('Missing eSIM product id.');
        }

        $existing = UserEsim::query()->where('esim_inventory_id', (int) $item->product_id)->first();
        if ($existing) {
            return;
        }

        $esim = EsimInventory::query()
            ->whereKey((int) $item->product_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($esim->status !== EsimInventory::STATUS_AVAILABLE) {
            throw new RuntimeException('eSIM already sold.');
        }

        $esim->update(['status' => EsimInventory::STATUS_SOLD]);
        $userEsim = UserEsim::query()->create([
            'user_id' => $user->id,
            'esim_inventory_id' => $esim->id,
            'order_id' => $order->id,
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        Mail::to($user->email)->send(new EsimPurchaseDeliveredMail($userEsim->fresh('esim', 'user')));
    }
}
