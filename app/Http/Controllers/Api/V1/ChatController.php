<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Services\ChatService;
use App\Services\SmsGatewayService;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly SmsGatewayService $smsGatewayService,
    ) {}

    public function conversations(): JsonResponse
    {
        $rows = Conversation::query()
            ->where('assigned_user_id', request()->user()->id)
            ->with('messages')
            ->latest('last_message_at')
            ->get();

        return $this->success(ConversationResource::collection($rows), 'Conversations fetched.');
    }

    public function numbers(): JsonResponse
    {
        $rows = request()->user()
            ->assignedPhoneNumbers()
            ->wherePivot('status', 'active')
            ->orderBy('phone_numbers.phone_number')
            ->get(['phone_numbers.id', 'phone_numbers.phone_number', 'phone_numbers.carrier_name', 'phone_numbers.sim_slot']);

        return $this->success($rows, 'Chat numbers fetched.');
    }

    public function send(SendMessageRequest $request): JsonResponse
    {
        $phoneNumber = PhoneNumber::query()->findOrFail($request->validated('phone_number_id'));
        $this->authorize('useForChat', $phoneNumber);
        $conversation = $this->chatService->getOrCreateConversation(
            $request->user(),
            $phoneNumber,
            $request->validated('contact_number'),
        );
        $message = $this->chatService->addOutboundMessage($conversation, $request->validated());
        $phoneNumber->loadMissing('device');
        $this->smsGatewayService->pushOutboundToDevice($message, $phoneNumber, $conversation);
        $this->smsGatewayService->pushChatUpdateToUser((int) $request->user()->id, [
            'event' => 'chat_updated',
            'conversation_id' => (int) $conversation->id,
        ]);

        return $this->success(new ConversationResource($conversation->fresh('messages')), 'Message queued.', 201);
    }

    public function destroy(Conversation $conversation): JsonResponse
    {
        if ((int) $conversation->assigned_user_id !== (int) request()->user()->id) {
            return $this->failure('Conversation not found.', 404);
        }

        $conversation->delete();

        return $this->success(null, 'Conversation deleted.');
    }
}
