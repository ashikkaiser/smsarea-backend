<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCampaignAiInboundReply;
use App\Models\Conversation;
use App\Models\Device;
use App\Models\Message;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\CampaignAiInboundService;
use App\Services\DeviceService;
use App\Services\SmsGatewayService;
use App\Support\SmsReactionParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AndroidGatewayController extends Controller
{
    public function __construct(
        private readonly DeviceService $deviceService,
        private readonly SmsGatewayService $smsGatewayService,
        private readonly CampaignAiInboundService $campaignAiInboundService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'device_uid' => ['required', 'string'],
            'model' => ['nullable', 'string'],
            'os' => ['nullable', 'string'],
            'sim_info' => ['required'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        Log::info('android.device.register_request', [
            'device_uid' => $data['device_uid'],
            'model' => $data['model'] ?? null,
            'os' => $data['os'] ?? null,
            'ip' => $request->ip(),
        ]);

        if (! $this->deviceService->validateRegistrationToken($data['token'])) {
            Log::warning('android.device.register_rejected', [
                'device_uid' => $data['device_uid'],
                'reason' => 'invalid_registration_token',
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid registration token'], 422);
        }

        $device = $this->deviceService->registerFromAndroid($data);
        $this->deviceService->consumeRegistrationToken($data['token']);

        Log::info('android.device.registered', [
            'device_uid' => $device->device_uid,
            'device_id' => $device->id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'device_uid' => $device->device_uid,
            'ws_url' => rtrim((string) config('services.sms_gateway.ws_url', config('app.url')), '/'),
            'device_token' => $device->device_token,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_token' => ['required', 'string'],
            'device_uid' => ['required', 'string'],
            'model' => ['nullable', 'string'],
            'os' => ['nullable', 'string'],
            'sim_info' => ['required'],
            'action' => ['nullable', 'string'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        Log::info('android.device.status_request', [
            'device_uid' => $data['device_uid'],
            'action' => $data['action'] ?? null,
            'model' => $data['model'] ?? null,
            'os' => $data['os'] ?? null,
            'ip' => $request->ip(),
        ]);

        $device = Device::query()
            ->where('device_uid', $data['device_uid'])
            ->where('device_token', $data['device_token'])
            ->first();

        if (! $device) {
            Log::warning('android.device.status_rejected', [
                'device_uid' => $data['device_uid'],
                'reason' => 'invalid_device_identity',
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid device identity'], 422);
        }

        $device = $this->deviceService->registerFromAndroid($data);

        Log::info('android.device.status_ok', [
            'device_uid' => $device->device_uid,
            'device_id' => $device->id,
            'action' => $data['action'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'device_uid' => $device->device_uid,
            'ws_url' => rtrim((string) config('services.sms_gateway.ws_url', config('app.url')), '/'),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uid' => ['required', 'string'],
            'device_token' => ['required', 'string'],
            'status' => ['required', 'in:online,offline,stale'],
        ]);

        $device = Device::query()
            ->where('device_uid', $data['device_uid'])
            ->where('device_token', $data['device_token'])
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Invalid device identity'], 422);
        }

        $device->forceFill([
            'status' => $data['status'],
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['message' => 'ok']);
    }

    public function receiveSms(Request $request): JsonResponse
    {
        Log::info('android.sms.receive_hit', [
            'device_id' => $request->input('device_id'),
            'contact_number' => $request->input('contact_number'),
            'receiver_number' => $request->input('receiver_number'),
            'sim_slot' => $request->input('sim_slot'),
            'ip' => $request->ip(),
        ]);

        try {
            $data = $request->validate([
                'device_id' => ['required', 'string'],
                'contact_number' => ['required', 'string'],
                'receiver_number' => ['nullable', 'string'],
                'message' => ['nullable', 'string'],
                'timestamp' => ['nullable'],
                'type' => ['nullable', 'string'],
                'message_type' => ['nullable', 'string'],
                'sim_slot' => ['nullable', 'integer'],
                'sim_info' => ['nullable'],
                'attachments' => ['nullable'],
                'is_reaction' => ['nullable', 'boolean'],
                'reaction_type' => ['nullable', 'string'],
                'reaction_action' => ['nullable', 'in:add,remove'],
                'reaction_target' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            Log::warning('android.sms.receive_validation_failed', [
                'errors' => $e->errors(),
                'device_id' => $request->input('device_id'),
                'ip' => $request->ip(),
            ]);
            throw $e;
        }

        $simInfo = $data['sim_info'] ?? [];
        if (is_string($simInfo)) {
            $decoded = json_decode($simInfo, true);
            $simInfo = is_array($decoded) ? $decoded : [];
        }

        $rawBody = $data['message'] ?? '';
        if (! ($data['is_reaction'] ?? false) && is_string($rawBody) && $rawBody !== '') {
            $parsedReaction = SmsReactionParser::parse($rawBody);
            if ($parsedReaction !== null) {
                $data['is_reaction'] = true;
                $data['reaction_type'] = $parsedReaction['type'];
                $data['reaction_action'] = $parsedReaction['action'];
                $data['reaction_target'] = $parsedReaction['target'];
            }
        }

        $phoneNumber = PhoneNumber::query()->firstOrCreate(
            ['phone_number' => $data['receiver_number'] ?? null],
            ['status' => 'active', 'carrier_name' => data_get($simInfo, 'carrier')],
        );

        $assignedUserId = $phoneNumber->users()
            ->wherePivot('status', 'active')
            ->value('users.id');
        $assignedUserId = $assignedUserId ?: User::query()->where('role', 'admin')->value('id');

        $conversation = Conversation::query()->firstOrCreate(
            [
                'phone_number_id' => $phoneNumber->id,
                'contact_number' => $data['contact_number'],
            ],
            [
                'assigned_user_id' => $assignedUserId,
                'status' => 'open',
            ],
        );

        $dedupeQuery = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('phone_number_id', $phoneNumber->id)
            ->where('direction', 'inbound')
            ->where('message_type', $data['message_type'] ?? 'sms')
            ->where('body', $data['message'] ?? '')
            ->where('sim_slot', $data['sim_slot'] ?? null);

        if (array_key_exists('timestamp', $data) && $data['timestamp'] !== null) {
            $dedupeQuery->where('meta->timestamp', $data['timestamp']);
        } else {
            // Fallback dedupe window when client doesn't provide a timestamp.
            $dedupeQuery->where('occurred_at', '>=', now()->subSeconds(30));
        }

        $existingMessage = $dedupeQuery->latest('id')->first();
        if ($existingMessage) {
            Log::info('android.sms.receive_duplicate_ignored', [
                'device_id' => $data['device_id'],
                'existing_message_id' => $existingMessage->id,
                'conversation_id' => $conversation->id,
                'phone_number_id' => $phoneNumber->id,
            ]);

            return response()->json([
                'message' => 'ok',
                'duplicate' => true,
                'message_id' => $existingMessage->id,
            ]);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'phone_number_id' => $phoneNumber->id,
            'direction' => 'inbound',
            'message_type' => $data['message_type'] ?? 'sms',
            'body' => $data['message'] ?? '',
            'attachments' => is_array($data['attachments'] ?? null) ? $data['attachments'] : [],
            'sim_slot' => $data['sim_slot'] ?? null,
            'status' => 'received',
            'occurred_at' => now(),
            'meta' => $data,
        ]);
        $conversation->update(['last_message_at' => now()]);

        Log::info('android.sms.receive_stored', [
            'device_id' => $data['device_id'],
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'phone_number_id' => $phoneNumber->id,
        ]);
        if ($assignedUserId) {
            $this->smsGatewayService->pushChatUpdateToUser((int) $assignedUserId, [
                'event' => 'chat_updated',
                'conversation_id' => (int) $conversation->id,
            ]);
        }

        if (
            ($data['message_type'] ?? 'sms') === 'sms'
            && ! ($data['is_reaction'] ?? false)
            && trim((string) ($data['message'] ?? '')) !== ''
            && $this->campaignAiInboundService->activeAiCampaignForPhone($phoneNumber)
        ) {
            $debounceSeconds = max(2, min(90, (int) config('services.ai.campaign_inbound_debounce_seconds', 10)));
            $debounceKey = 'campaign_ai_debounce_until:'.$conversation->id;
            Cache::put(
                $debounceKey,
                now()->addSeconds($debounceSeconds)->getTimestamp(),
                $debounceSeconds * 4 + 120,
            );
            Bus::dispatch(
                (new ProcessCampaignAiInboundReply((int) $conversation->id))
                    ->delay(now()->addSeconds($debounceSeconds)),
            );
        }

        return response()->json(['message' => 'ok']);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string'],
            'message_id' => ['required', 'integer'],
            'status' => ['required', 'in:sent,delivered,failed'],
        ]);

        // Gateway push uses server Message id (SmsGatewayService); Android echoes it as message_id.
        $updated = Message::query()
            ->where(function ($query) use ($data): void {
                $query->where('id', $data['message_id'])
                    ->orWhere('device_message_id', $data['message_id']);
            })
            ->update(['status' => $data['status']]);

        Log::info('android.sms.update_status', [
            'message_id_param' => $data['message_id'],
            'status' => $data['status'],
            'rows_updated' => $updated,
            'device_uid' => $data['device_id'],
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'status updated', 'updated' => $updated]);
    }

    public function mmsReceived(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string'],
            'contact_number' => ['nullable', 'string'],
            'receiver_number' => ['nullable', 'string'],
            'message' => ['nullable', 'string'],
            'sim_slot' => ['nullable', 'integer'],
        ]);

        $phoneNumber = PhoneNumber::query()->firstOrCreate(
            ['phone_number' => $data['receiver_number'] ?? null],
            ['status' => 'active'],
        );
        $assignedUserId = $phoneNumber->users()->wherePivot('status', 'active')->value('users.id')
            ?: User::query()->where('role', 'admin')->value('id');
        $conversation = Conversation::query()->firstOrCreate(
            ['phone_number_id' => $phoneNumber->id, 'contact_number' => $data['contact_number'] ?? 'unknown'],
            ['assigned_user_id' => $assignedUserId, 'status' => 'open'],
        );

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'phone_number_id' => $phoneNumber->id,
            'direction' => 'inbound',
            'message_type' => 'mms',
            'body' => $data['message'] ?? 'MMS incoming',
            'attachments' => [],
            'sim_slot' => $data['sim_slot'] ?? null,
            'status' => 'received',
            'occurred_at' => now(),
            'meta' => ['phase' => 'received'],
        ]);
        $conversation->update(['last_message_at' => now()]);
        if ($assignedUserId) {
            $this->smsGatewayService->pushChatUpdateToUser((int) $assignedUserId, [
                'event' => 'chat_updated',
                'conversation_id' => (int) $conversation->id,
            ]);
        }

        return response()->json(['sms_message_id' => (string) $message->id]);
    }

    public function mmsAttachment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string'],
            'device_id' => ['required', 'string'],
            'sms_id' => ['nullable', 'string'],
            'sms_is' => ['nullable', 'string'],
            'message' => ['nullable', 'string'],
            'attachments' => ['nullable'],
        ]);

        $messageId = $data['sms_id'] ?? $data['sms_is'] ?? null;
        if ($messageId) {
            Message::query()->whereKey((int) $messageId)->update([
                'body' => $data['message'] ?? null,
                'attachments' => is_array($data['attachments'] ?? null) ? $data['attachments'] : [],
                'meta' => ['phase' => 'attachment'],
            ]);
        }

        return response()->json(['message' => 'attachment accepted']);
    }
}
