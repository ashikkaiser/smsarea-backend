<?php

namespace App\Http\Requests\Campaign;

use App\Models\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CampaignUpdateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'agent_name' => ['nullable', 'string', 'max:255'],
            'entry_message_template' => ['nullable', 'string'],
            'ai_inbound_enabled' => ['nullable', 'boolean'],
            'ai_inbound_system_prompt' => ['nullable', 'string', 'max:32000'],
            'status' => ['nullable', 'in:draft,active,paused,archived'],
            'settings' => ['nullable', 'array'],
            'steps' => ['nullable', 'array'],
            'steps.*.step_order' => ['required_with:steps', 'integer', 'min:1'],
            'steps.*.step_type' => ['required_with:steps', 'in:initial,reply,followup'],
            'steps.*.message_template' => ['required_with:steps', 'string'],
            'steps.*.delay_seconds' => ['nullable', 'integer', 'min:0'],
            'steps.*.is_active' => ['nullable', 'boolean'],
            'steps.*.conditions' => ['nullable', 'array'],
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
