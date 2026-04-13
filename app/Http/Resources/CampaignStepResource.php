<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'step_order' => $this->step_order,
            'step_type' => $this->step_type,
            'message_template' => $this->message_template,
            'delay_seconds' => $this->delay_seconds,
            'is_active' => $this->is_active,
            'conditions' => $this->conditions,
        ];
    }
}
