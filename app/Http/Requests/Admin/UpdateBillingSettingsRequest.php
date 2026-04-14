<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'default_price_minor' => ['sometimes', 'integer', 'min:0'],
            'device_slot_price_minor' => ['sometimes', 'integer', 'min:0'],
            'esim_price_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'default_duration_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'self_checkout_enabled' => ['sometimes', 'boolean'],
            'nowpayments_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nowpayments_ipn_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nowpayments_pay_currency' => ['sometimes', 'string', 'max:32'],
            'nowpayments_sandbox' => ['sometimes', 'boolean'],
            'checkout_success_path' => ['sometimes', 'string', 'max:255'],
            'checkout_cancel_path' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
