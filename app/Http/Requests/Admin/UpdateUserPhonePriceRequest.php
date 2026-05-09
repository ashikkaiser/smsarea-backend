<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserPhonePriceRequest extends FormRequest
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
            'price_minor_per_period' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'device_slot_price_minor' => ['nullable', 'integer', 'min:0'],
            /** @deprecated Use esim_carrier_overrides */
            'esim_price_minor' => ['nullable', 'integer', 'min:0'],
            'esim_carrier_overrides' => ['nullable', 'array'],
            'esim_carrier_overrides.*.esim_carrier_plan_id' => ['required', 'integer', 'exists:esim_carrier_plans,id'],
            'esim_carrier_overrides.*.price_minor' => ['required', 'integer', 'min:0'],
        ];
    }
}
