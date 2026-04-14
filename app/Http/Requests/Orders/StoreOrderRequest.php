<?php

namespace App\Http\Requests\Orders;

use App\Models\OrderItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
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
            'product_type' => ['required', 'string', Rule::in([
                OrderItem::PRODUCT_NUMBER,
                OrderItem::PRODUCT_DEVICE_SLOT,
                OrderItem::PRODUCT_ESIM,
            ])],
            'phone_number_id' => ['nullable', 'integer', 'exists:phone_numbers,id'],
            'esim_inventory_id' => ['nullable', 'integer', 'exists:esim_inventories,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = (string) $this->input('product_type');

            if ($type === OrderItem::PRODUCT_NUMBER && ! $this->filled('phone_number_id')) {
                $validator->errors()->add('phone_number_id', 'phone_number_id is required for number orders.');
            }
            if ($type === OrderItem::PRODUCT_ESIM && ! $this->filled('esim_inventory_id')) {
                $validator->errors()->add('esim_inventory_id', 'esim_inventory_id is required for eSIM orders.');
            }
        });
    }
}
