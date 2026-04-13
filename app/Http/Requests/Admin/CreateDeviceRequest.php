<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateDeviceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_uid' => ['required', 'string', 'max:255'],
            'device_token' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:255'],
            'sim_info' => ['nullable', 'array'],
            'sim_info.*.slot' => ['required_with:sim_info', 'integer', 'min:1'],
            'sim_info.*.number' => ['nullable', 'string', 'max:64'],
            'sim_info.*.carrier' => ['nullable', 'string', 'max:255'],
        ];
    }
}
