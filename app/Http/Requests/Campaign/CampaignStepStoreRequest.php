<?php

namespace App\Http\Requests\Campaign;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CampaignStepStoreRequest extends FormRequest
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
            'step_order' => ['required', 'integer', 'min:1'],
            'step_type' => ['required', 'in:initial,reply,followup'],
            'message_template' => ['required', 'string'],
            'delay_seconds' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'conditions' => ['nullable', 'array'],
        ];
    }
}
