<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCampaignBuilderLimitsRequest extends FormRequest
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
            'max_reply_steps' => ['required', 'integer', 'min:1', 'max:50'],
            'max_followup_steps' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
