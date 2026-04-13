<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'agent_name' => $this->agent_name,
            'status' => $this->status,
            'entry_message_template' => $this->entry_message_template,
            'ai_inbound_enabled' => (bool) $this->ai_inbound_enabled,
            'ai_inbound_system_prompt' => $this->ai_inbound_system_prompt,
            'settings' => $this->settings,
            'steps' => CampaignStepResource::collection($this->whenLoaded('steps')),
            'phone_numbers' => PhoneNumberResource::collection($this->whenLoaded('phoneNumbers')),
            'numbers_count' => $this->whenLoaded('phoneNumbers', fn (): int => $this->phoneNumbers->count()),
            'statistics' => $this->statisticsFromSettings(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Per-campaign counters for the UI. Merge from settings.statistics (integers).
     * Defaults are zero until outbound/delivery pipelines persist real values.
     *
     * @return array{sent: int, pending: int, total: int, delivered: int, failed: int}
     */
    private function statisticsFromSettings(): array
    {
        $defaults = [
            'sent' => 0,
            'pending' => 0,
            'total' => 0,
            'delivered' => 0,
            'failed' => 0,
        ];

        $settings = is_array($this->settings) ? $this->settings : [];
        $raw = isset($settings['statistics']) && is_array($settings['statistics'])
            ? $settings['statistics']
            : [];

        $out = $defaults;
        foreach (array_keys($defaults) as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $v = $raw[$key];
            $out[$key] = is_numeric($v) ? (int) $v : 0;
        }

        return $out;
    }
}
