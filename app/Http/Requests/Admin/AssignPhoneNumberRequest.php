<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignPhoneNumberRequest extends FormRequest
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
    protected function prepareForValidation(): void
    {
        if ($this->has('phone_number')) {
            $this->merge([
                'phone_number' => trim((string) $this->input('phone_number')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required_without:phone_number_id', 'nullable', 'string', 'min:7', 'max:40'],
            'phone_number_id' => ['required_without:phone_number', 'nullable', 'integer', 'exists:phone_numbers,id'],
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', 'user'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('phone_number') && ! $this->filled('phone_number_id')) {
                $validator->errors()->add('phone_number', 'Provide a phone number or phone number id.');
            }
        });
    }
}
