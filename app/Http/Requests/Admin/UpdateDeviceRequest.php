<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'custom_name' => ['nullable', 'string', 'max:128'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('custom_name') && is_string($this->input('custom_name'))) {
            $trimmed = trim($this->input('custom_name'));
            $this->merge([
                'custom_name' => $trimmed === '' ? null : $trimmed,
            ]);
        }
    }
}
