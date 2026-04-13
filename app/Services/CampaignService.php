<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    public function createCampaign(User $user, array $data): Campaign
    {
        return DB::transaction(function () use ($user, $data): Campaign {
            $campaign = Campaign::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'agent_name' => $data['agent_name'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'entry_message_template' => $data['entry_message_template'] ?? null,
                'ai_inbound_enabled' => (bool) ($data['ai_inbound_enabled'] ?? false),
                'ai_inbound_system_prompt' => self::normalizedAiInboundSystemPrompt($data['ai_inbound_system_prompt'] ?? null),
                'settings' => $data['settings'] ?? null,
            ]);

            if (array_key_exists('phone_number_ids', $data)) {
                $this->syncCampaignPhoneNumbers($user, $campaign, $data['phone_number_ids'] ?? []);
            }

            return $campaign->fresh();
        });
    }

    /**
     * @param  array<int, mixed>  $phoneNumberIds
     */
    public function syncCampaignPhoneNumbers(User $actingUser, Campaign $campaign, array $phoneNumberIds): void
    {
        $ids = collect($phoneNumberIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->assertPhoneNumbersAvailableForCampaign($campaign, $ids);

        $payload = [];
        foreach ($ids as $id) {
            $payload[$id] = [
                'assigned_by' => $actingUser->id,
                'assigned_at' => now(),
            ];
        }

        $campaign->phoneNumbers()->sync($payload);
    }

    /**
     * Each phone line may appear in at most one campaign (globally).
     *
     * @param  array<int, int>  $phoneNumberIds
     *
     * @throws ValidationException
     */
    public function assertPhoneNumbersAvailableForCampaign(Campaign $campaign, array $phoneNumberIds): void
    {
        $ids = collect($phoneNumberIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return;
        }

        $rows = DB::table('campaign_phone_number')
            ->whereIn('phone_number_id', $ids)
            ->where('campaign_id', '!=', $campaign->id)
            ->join('campaigns', 'campaigns.id', '=', 'campaign_phone_number.campaign_id')
            ->select(['phone_number_id', 'campaigns.id as campaign_id', 'campaigns.name as campaign_name'])
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $details = $rows
            ->map(fn ($r) => __('number #:id → «:name»', ['id' => $r->phone_number_id, 'name' => $r->campaign_name]))
            ->join('; ');

        throw ValidationException::withMessages([
            'phone_number_ids' => __('Each phone line can only belong to one campaign. Already assigned: :details.', [
                'details' => $details,
            ]),
        ]);
    }

    public function attachPhoneNumberToCampaign(User $actingUser, Campaign $campaign, PhoneNumber $phoneNumber): void
    {
        $this->assertPhoneNumbersAvailableForCampaign($campaign, [$phoneNumber->id]);

        $campaign->phoneNumbers()->syncWithoutDetaching([
            $phoneNumber->id => [
                'assigned_by' => $actingUser->id,
                'assigned_at' => now(),
            ],
        ]);
    }

    public function addStep(Campaign $campaign, array $data): void
    {
        $campaign->steps()->create([
            'step_order' => $data['step_order'],
            'step_type' => $data['step_type'],
            'message_template' => $data['message_template'],
            'delay_seconds' => $data['delay_seconds'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'conditions' => $data['conditions'] ?? null,
        ]);
    }

    public function updateStatus(Campaign $campaign, string $status): Campaign
    {
        if ($status === 'active') {
            $this->assertCampaignMayActivate($campaign);
        }

        $campaign->update(['status' => $status]);

        return $campaign->fresh(['steps', 'phoneNumbers']);
    }

    /**
     * Activating a campaign requires outbound lines and a complete message ladder (entry, reply, follow-up).
     *
     * @throws ValidationException
     */
    private function assertCampaignMayActivate(Campaign $campaign): void
    {
        $campaign->loadMissing(['steps', 'phoneNumbers']);

        if ($campaign->phoneNumbers->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => __('Assign at least one phone number to this campaign before activating.'),
            ]);
        }

        $entry = trim((string) ($campaign->entry_message_template ?? ''));
        if ($entry === '') {
            throw ValidationException::withMessages([
                'status' => __('Set an entry message template before activating.'),
            ]);
        }

        $hasReply = $campaign->steps->contains(function ($step): bool {
            return $step->step_type === 'reply'
                && trim((string) ($step->message_template ?? '')) !== '';
        });

        if (! $hasReply) {
            throw ValidationException::withMessages([
                'status' => __('Add at least one Reply step with message text before activating.'),
            ]);
        }

        $hasFollowup = $campaign->steps->contains(function ($step): bool {
            return $step->step_type === 'followup'
                && trim((string) ($step->message_template ?? '')) !== '';
        });

        if (! $hasFollowup) {
            throw ValidationException::withMessages([
                'status' => __('Add at least one Follow-up step with message text before activating.'),
            ]);
        }
    }

    public function updateCampaign(Campaign $campaign, array $data, ?User $actingUser = null): Campaign
    {
        $actor = $actingUser ?? User::query()->findOrFail($campaign->user_id);

        return DB::transaction(function () use ($campaign, $data, $actor): Campaign {
            $campaign->update([
                'name' => $data['name'],
                'agent_name' => $data['agent_name'] ?? null,
                'status' => $data['status'] ?? $campaign->status,
                'entry_message_template' => $data['entry_message_template'] ?? null,
                'ai_inbound_enabled' => array_key_exists('ai_inbound_enabled', $data)
                    ? (bool) $data['ai_inbound_enabled']
                    : $campaign->ai_inbound_enabled,
                'ai_inbound_system_prompt' => array_key_exists('ai_inbound_system_prompt', $data)
                    ? self::normalizedAiInboundSystemPrompt($data['ai_inbound_system_prompt'] ?? null)
                    : $campaign->ai_inbound_system_prompt,
                'settings' => $data['settings'] ?? null,
            ]);

            if (array_key_exists('steps', $data)) {
                $campaign->steps()->delete();
                foreach ($data['steps'] as $step) {
                    $campaign->steps()->create([
                        'step_order' => $step['step_order'],
                        'step_type' => $step['step_type'],
                        'message_template' => $step['message_template'],
                        'delay_seconds' => $step['delay_seconds'] ?? 0,
                        'is_active' => $step['is_active'] ?? true,
                        'conditions' => $step['conditions'] ?? null,
                    ]);
                }
            }

            if (array_key_exists('phone_number_ids', $data)) {
                $this->syncCampaignPhoneNumbers($actor, $campaign, $data['phone_number_ids'] ?? []);
            }

            $campaign->refresh();

            $nextStatus = $data['status'] ?? $campaign->status;
            if ($nextStatus === 'active') {
                $this->assertCampaignMayActivate($campaign);
            }

            return $campaign->fresh(['steps', 'phoneNumbers']);
        });
    }

    private static function normalizedAiInboundSystemPrompt(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
