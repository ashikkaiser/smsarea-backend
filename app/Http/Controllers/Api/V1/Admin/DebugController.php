<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Message;
use Illuminate\Http\JsonResponse;

/**
 * Read-only inspection helpers. Interactive REPL belongs in SSH: php artisan tinker
 */
class DebugController extends Controller
{
    use ApiResponse;

    public function messageById(Message $message): JsonResponse
    {
        $message->load(['conversation', 'phoneNumber', 'phoneNumber.device']);

        $row = $message->toArray();
        if ($message->device_id) {
            $row['device'] = Device::query()->find($message->device_id)?->only(['id', 'device_uid', 'model', 'os', 'status']);
        }

        return $this->success($row, 'Message fetched.');
    }

    public function messageByDeviceMessageId(int $deviceMessageId): JsonResponse
    {
        $message = Message::query()
            ->where('device_message_id', $deviceMessageId)
            ->orderByDesc('id')
            ->first();

        if (! $message) {
            return $this->failure('No message with this device_message_id.', 404);
        }

        return $this->messageById($message);
    }
}
