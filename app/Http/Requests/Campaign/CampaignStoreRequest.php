<?php

namespace App\Http\Requests\Campaign;

use App\Models\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CampaignStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'agent_name' => ['nullable', 'string', 'max:255'],
            'entry_message_template' => ['nullable', 'string'],
            'ai_inbound_enabled' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:draft,active,paused,archived'],
            'settings' => ['nullable', 'array'],
            'phone_number_ids' => ['nullable', 'array'],
            'phone_number_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $ids = $this->input('phone_number_ids');
            if (! is_array($ids) || $ids === []) {
                return;
            }
            $user = $this->user();
            foreach ($ids as $id) {
                $phone = PhoneNumber::query()->find((int) $id);
                if (! $phone || ! $user?->can('useForChat', $phone)) {
                    $v->errors()->add('phone_number_ids', 'One or more numbers are invalid or not assigned to you.');

                    return;
                }
            }
        });
    }
}
