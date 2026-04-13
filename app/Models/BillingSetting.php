<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingSetting extends Model
{
    protected $fillable = [
        'default_price_minor',
        'currency',
        'default_duration_days',
        'self_checkout_enabled',
        'nowpayments_api_key',
        'nowpayments_ipn_secret',
        'nowpayments_pay_currency',
        'nowpayments_sandbox',
        'checkout_success_path',
        'checkout_cancel_path',
    ];

    protected function casts(): array
    {
        return [
            'default_price_minor' => 'integer',
            'default_duration_days' => 'integer',
            'self_checkout_enabled' => 'boolean',
            'nowpayments_sandbox' => 'boolean',
            'nowpayments_api_key' => 'encrypted',
            'nowpayments_ipn_secret' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        $key = $this->nowpayments_api_key;
        $secret = $this->nowpayments_ipn_secret;

        return [
            'default_price_minor' => (int) $this->default_price_minor,
            'currency' => strtoupper((string) $this->currency),
            'default_duration_days' => (int) $this->default_duration_days,
            'self_checkout_enabled' => (bool) $this->self_checkout_enabled,
            'nowpayments_pay_currency' => $this->nowpayments_pay_currency,
            'nowpayments_sandbox' => (bool) $this->nowpayments_sandbox,
            'checkout_success_path' => $this->checkout_success_path,
            'checkout_cancel_path' => $this->checkout_cancel_path,
            'nowpayments_api_key_set' => $key !== null && $key !== '',
            'nowpayments_ipn_secret_set' => $secret !== null && $secret !== '',
        ];
    }
}
