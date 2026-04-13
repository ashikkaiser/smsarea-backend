<?php

namespace App\Http\Requests\Numbers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePhoneNumberOrderRequest extends FormRequest
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
            'phone_number_id' => ['required', 'integer', 'exists:phone_numbers,id'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
