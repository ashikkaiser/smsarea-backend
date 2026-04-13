<?php

namespace App\Http\Requests\Chat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
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
            'phone_number_id' => ['required', 'exists:phone_numbers,id'],
            'contact_number' => ['required', 'string', 'max:32'],
            'message' => ['required', 'string'],
            'message_type' => ['nullable', 'in:sms,mms'],
            'attachments' => ['nullable', 'array'],
        ];
    }
}
