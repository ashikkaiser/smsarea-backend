<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsGatewayService
{
    /**
     * @return array<int, string>
     */
    public function connectedDeviceUids(): array
    {
        $base = config('services.sms_gateway.http_url');
        if (! is_string($base) || $base === '') {
            return [];
        }

        $url = rtrim($base, '/').'/connected-devices';

        try {
            $response = Http::timeout(5)->acceptJson()->get($url);
            if (! $response->successful()) {
                return [];
            }

            $uids = $response->json('device_uids', []);
            if (! is_array($uids)) {
                return [];
            }

            return array_values(array_filter(array_map(
                static fn ($value) => is_string($value) ? trim($value) : '',
                $uids,
            )));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Push outbound chat messages to the FastAPI gateway so the device receives payload.action send_sms / send_mms. */
    public function pushOutboundToDevice(Message $message, PhoneNumber $phoneNumber, Conversation $conversation): void
    {
        $base = config('services.sms_gateway.http_url');
        if (! is_string($base) || $base === '') {
            return;
        }

        $deviceUid = $phoneNumber->device?->device_uid;
        if (! $deviceUid) {
            return;
        }

        $messageType = $message->message_type ?? 'sms';
        $path = $messageType === 'mms' ? 'send_mms' : 'send_sms';
        $url = rtrim($base, '/').'/'.$path.'/'.$deviceUid;

        $body = [
            'message_id' => $message->id,
            'receiver_number' => $conversation->contact_number,
            'message' => (string) ($message->body ?? ''),
            'sim_slot' => (int) ($phoneNumber->sim_slot ?? 0),
        ];

        if ($messageType === 'mms') {
            $body['attachments'] = is_array($message->attachments) ? $message->attachments : [];
        }

        try {
            $response = Http::timeout(8)->acceptJson()->asJson()->post($url, $body);
            if (! $response->successful()) {
                Log::warning('sms_gateway.push_failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('sms_gateway.push_exception', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Push lightweight chat update event to the web user socket gateway.
     *
     * @param  array<string, mixed>  $payload
     */
    public function pushChatUpdateToUser(int $userId, array $payload): void
    {
        $base = config('services.sms_gateway.http_url');
        if (! is_string($base) || $base === '') {
            return;
        }

        $url = rtrim($base, '/').'/push-to-user';

        try {
            $response = Http::timeout(5)->acceptJson()->asJson()->post($url, [
                'user_id' => $userId,
                'payload' => $payload,
            ]);

            if (! $response->successful() && $response->status() !== 202) {
                Log::warning('sms_gateway.user_push_failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('sms_gateway.user_push_exception', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
