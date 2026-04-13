<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberOrder;
use App\Models\PhoneNumberPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhoneNumberService
{
    public function assignToUser(PhoneNumber $phoneNumber, User $user, User $actor): void
    {
        $phoneNumber->users()->syncWithoutDetaching([
            $user->id => [
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'status' => 'active',
            ],
        ]);

        AuditLog::create([
            'actor_user_id' => $actor->id,
            'action' => 'phone_number.assigned',
            'entity_type' => 'phone_number',
            'entity_id' => (string) $phoneNumber->id,
            'after' => ['user_id' => $user->id],
        ]);
    }

    /**
     * Remove every user assignment for this line, revoke active subscriptions, and cancel open checkouts
     * so the number can be reassigned or sold again. Uses pivot deletes (not status=inactive) to avoid
     * unique(phone_number_id, status) collisions when unassigning more than once over time.
     */
    public function unassignAllUsers(PhoneNumber $phoneNumber, User $actor): void
    {
        DB::transaction(function () use ($phoneNumber, $actor): void {
            $phoneNumberId = $phoneNumber->id;

            $hadActiveAssignment = $phoneNumber->users()
                ->wherePivot('status', 'active')
                ->exists();

            PhoneNumberPurchase::query()
                ->where('phone_number_id', $phoneNumberId)
                ->where('status', 'active')
                ->update([
                    'status' => 'revoked',
                    'expiry_date' => now(),
                ]);

            PhoneNumberOrder::query()
                ->where('phone_number_id', $phoneNumberId)
                ->whereIn('status', [
                    PhoneNumberOrder::STATUS_AWAITING_PAYMENT,
                    PhoneNumberOrder::STATUS_CONFIRMING,
                    PhoneNumberOrder::STATUS_PAID,
                ])
                ->update(['status' => PhoneNumberOrder::STATUS_CANCELLED]);

            DB::table('phone_number_user')->where('phone_number_id', $phoneNumberId)->delete();

            if ($hadActiveAssignment) {
                AuditLog::create([
                    'actor_user_id' => $actor->id,
                    'action' => 'phone_number.unassigned_all',
                    'entity_type' => 'phone_number',
                    'entity_id' => (string) $phoneNumberId,
                    'after' => ['released_for_reassignment' => true],
                ]);
            }
        });
    }

    /**
     * Hard-delete a phone number and all related chat data and pivots.
     * Messages and conversations are removed explicitly first (then the row); pivots and purchases
     * still cascade from {@see PhoneNumber} deletion so the DB stays consistent if FK definitions differ.
     */
    public function deleteNumber(PhoneNumber $phoneNumber, User $actor): void
    {
        $phoneNumber->loadMissing('device:id,device_uid,model,custom_name');

        $impact = $phoneNumber->deleteImpactCounts();

        if (($impact['active_user_assignments'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'phone_number' => [
                    'This line still has active user assignments. Unassign everyone on Assignments first, then delete the line here.',
                ],
            ]);
        }

        $id = (string) $phoneNumber->id;
        $before = [
            'phone_number' => $phoneNumber->phone_number,
            'carrier_name' => $phoneNumber->carrier_name,
            'device_id' => $phoneNumber->device_id,
            'impact' => $impact,
        ];

        DB::transaction(function () use ($phoneNumber, $actor, $id, $before): void {
            $pnId = $phoneNumber->id;

            Message::query()->where('phone_number_id', $pnId)->delete();
            Conversation::query()->where('phone_number_id', $pnId)->delete();

            $phoneNumber->delete();

            AuditLog::create([
                'actor_user_id' => $actor->id,
                'action' => 'phone_number.deleted',
                'entity_type' => 'phone_number',
                'entity_id' => $id,
                'before' => $before,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $impact
     * @return list<string>
     */
    public function warningsForImpact(array $impact): array
    {
        $warnings = [];

        if (! empty($impact['linked_device'])) {
            $warnings[] = 'This number is still linked to a registered device. After deletion, the handset may recreate a line on the next sync; unassign users and verify the device state if needed.';
        }

        if (($impact['campaign_links'] ?? 0) > 0) {
            $warnings[] = 'Campaign associations for this number will be removed; campaign definitions remain.';
        }

        if (($impact['conversations'] ?? 0) > 0 || ($impact['messages'] ?? 0) > 0) {
            $warnings[] = 'All chat history (conversations and messages) for this number will be permanently deleted.';
        }

        if (($impact['purchases'] ?? 0) > 0) {
            $warnings[] = 'Purchase and renewal records for this number will be deleted.';
        }

        return $warnings;
    }
}
