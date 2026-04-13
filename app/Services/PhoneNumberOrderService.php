<?php

namespace App\Services;

use App\Models\BillingSetting;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberOrder;
use App\Models\PhoneNumberPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PhoneNumberOrderService
{
    public function __construct(
        private readonly BillingPricingService $pricing,
        private readonly NowPaymentsClient $nowPayments,
        private readonly NumberPurchaseService $numberPurchaseService,
        private readonly PhoneNumberService $phoneNumberService,
    ) {}

    public function availableNumbersQuery()
    {
        return PhoneNumber::query()
            ->where('status', '!=', 'expired')
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->whereDoesntHave('users', function ($q): void {
                // Pivot filters must use the pivot table name; `wherePivot` only exists on the relation, not this builder.
                $q->where('phone_number_user.status', 'active');
            })
            ->whereDoesntHave('orders', function ($q): void {
                $q->whereIn('status', [
                    PhoneNumberOrder::STATUS_AWAITING_PAYMENT,
                    PhoneNumberOrder::STATUS_CONFIRMING,
                    PhoneNumberOrder::STATUS_PAID,
                ]);
            })
            ->orderBy('phone_number');
    }

    /**
     * @return array{order: PhoneNumberOrder, payment: array<string, mixed>}
     */
    public function createCheckoutOrder(User $user, PhoneNumber $phoneNumber, ?int $durationDays = null): array
    {
        $settings = BillingSetting::current();
        if (! $settings->self_checkout_enabled) {
            throw new RuntimeException('Self-checkout is disabled.');
        }

        if (! $this->availableNumbersQuery()->whereKey($phoneNumber->getKey())->exists()) {
            throw new RuntimeException('This number is not available for purchase.');
        }

        $quote = $this->pricing->quoteForUser($user, $durationDays);

        return DB::transaction(function () use ($user, $phoneNumber, $quote, $settings) {
            $order = PhoneNumberOrder::create([
                'user_id' => $user->id,
                'phone_number_id' => $phoneNumber->id,
                'duration_days' => $quote['duration_days'],
                'amount_minor' => $quote['amount_minor'],
                'currency' => $quote['currency'],
                'status' => PhoneNumberOrder::STATUS_AWAITING_PAYMENT,
                'source' => PhoneNumberOrder::SOURCE_USER_SELF,
                'provider' => 'nowpayments',
                'meta' => [],
            ]);

            $appUrl = rtrim((string) config('app.url'), '/');
            $successUrl = $appUrl.$settings->checkout_success_path;
            $cancelUrl = $appUrl.$settings->checkout_cancel_path;
            $ipnUrl = $appUrl.'/api/v1/webhooks/nowpayments';

            $priceMajor = $quote['amount_minor'] / 100;
            $payload = [
                'price_amount' => round($priceMajor, 2),
                'price_currency' => strtolower($quote['currency']),
                'pay_currency' => strtolower((string) $settings->nowpayments_pay_currency),
                'order_id' => (string) $order->id,
                'order_description' => 'Phone number #'.$phoneNumber->id,
                'ipn_callback_url' => $ipnUrl,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ];

            $payment = $this->nowPayments->createPayment($settings, $payload);

            $order->update([
                'provider_payment_id' => isset($payment['payment_id']) ? (string) $payment['payment_id'] : null,
                'provider_pay_address' => isset($payment['pay_address']) ? (string) $payment['pay_address'] : null,
                'provider_pay_currency' => isset($payment['pay_currency']) ? (string) $payment['pay_currency'] : null,
                'provider_pay_amount' => isset($payment['pay_amount']) ? $payment['pay_amount'] : null,
                'meta' => array_merge($order->meta ?? [], ['provider_create_response' => $payment]),
            ]);

            return ['order' => $order->fresh(), 'payment' => $payment];
        });
    }

    public function fulfillPaidOrder(PhoneNumberOrder $order): void
    {
        if ($order->status === PhoneNumberOrder::STATUS_FULFILLED) {
            return;
        }

        DB::transaction(function () use ($order): void {
            $order->refresh();
            if ($order->status === PhoneNumberOrder::STATUS_FULFILLED) {
                return;
            }

            $user = $order->user;
            $phoneNumber = $order->phoneNumber;

            $purchase = $this->numberPurchaseService->purchase($user, $phoneNumber, [
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'duration_days' => $order->duration_days,
                'auto_renew' => false,
            ]);

            $this->phoneNumberService->assignToUser($phoneNumber, $user, $user);

            $order->update([
                'status' => PhoneNumberOrder::STATUS_FULFILLED,
                'phone_number_purchase_id' => $purchase->id,
            ]);
        });
    }

    public function markOrderPaidIfAwaiting(PhoneNumberOrder $order): void
    {
        if (in_array($order->status, [PhoneNumberOrder::STATUS_AWAITING_PAYMENT, PhoneNumberOrder::STATUS_CONFIRMING], true)) {
            $order->update(['status' => PhoneNumberOrder::STATUS_PAID]);
        }
    }

    /**
     * Admin assignment audit trail + $0 subscription row for My Numbers when missing.
     * Call after {@see PhoneNumberService::assignToUser()} has already run.
     */
    public function recordAdminProvision(PhoneNumber $phoneNumber, User $assignee, User $actor): PhoneNumberOrder
    {
        $settings = BillingSetting::current();
        $quote = $this->pricing->quoteForUser($assignee, (int) $settings->default_duration_days);

        return DB::transaction(function () use ($phoneNumber, $assignee, $actor, $quote) {
            $order = PhoneNumberOrder::create([
                'user_id' => $assignee->id,
                'phone_number_id' => $phoneNumber->id,
                'duration_days' => $quote['duration_days'],
                'amount_minor' => 0,
                'currency' => $quote['currency'],
                'status' => PhoneNumberOrder::STATUS_WAIVED,
                'source' => PhoneNumberOrder::SOURCE_ADMIN_ASSIGN,
                'assigned_by_user_id' => $actor->id,
                'provider' => null,
                'meta' => ['note' => 'Provisioned by admin assignment'],
            ]);

            $hasActivePurchase = PhoneNumberPurchase::query()
                ->where('user_id', $assignee->id)
                ->where('phone_number_id', $phoneNumber->id)
                ->where('status', 'active')
                ->where('expiry_date', '>', now())
                ->exists();

            if (! $hasActivePurchase) {
                try {
                    $purchase = $this->numberPurchaseService->purchase($assignee, $phoneNumber, [
                        'amount_minor' => 0,
                        'currency' => $quote['currency'],
                        'duration_days' => $quote['duration_days'],
                        'auto_renew' => false,
                    ]);
                    $order->update([
                        'phone_number_purchase_id' => $purchase->id,
                        'status' => PhoneNumberOrder::STATUS_FULFILLED,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Admin assign purchase mirror failed', ['error' => $e->getMessage()]);
                }
            } else {
                $order->update(['status' => PhoneNumberOrder::STATUS_FULFILLED]);
            }

            return $order->fresh();
        });
    }
}
