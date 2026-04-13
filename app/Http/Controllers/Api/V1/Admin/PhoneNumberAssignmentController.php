<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignPhoneNumberRequest;
use App\Http\Requests\Admin\DeletePhoneNumberRequest;
use App\Http\Requests\Admin\UpdatePhoneNumberRequest;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\PhoneNumberOrderService;
use App\Services\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PhoneNumberAssignmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
        private readonly PhoneNumberOrderService $phoneNumberOrderService,
    ) {}

    public function index(): JsonResponse
    {
        $validated = request()->validate([
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:200'],
            'status' => ['sometimes', 'string', 'max:32'],
            'carrier' => ['sometimes', 'string', 'max:255'],
            'search' => ['sometimes', 'string', 'max:64'],
            'assigned' => ['sometimes', 'string', 'in:all,unassigned,assigned'],
            'user_id' => [
                'sometimes',
                'integer',
                Rule::exists('users', 'id')->where('role', 'user'),
            ],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $perPage = max(5, min($perPage, 200));

        $query = PhoneNumber::query()
            ->with([
                'device:id,device_uid,model,custom_name',
                'users' => function ($query): void {
                    $query->wherePivot('status', 'active');
                },
            ]);

        if (! empty($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['carrier'] ?? null)) {
            $needle = trim((string) $validated['carrier']);
            $query->whereRaw('LOWER(TRIM(carrier_name)) = LOWER(?)', [$needle]);
        }

        if (! empty($validated['search'] ?? null)) {
            $raw = trim($validated['search']);
            $digits = preg_replace('/\D+/', '', $raw) ?? '';
            $query->where(function ($q) use ($raw, $digits): void {
                $q->where('phone_number', 'like', '%'.addcslashes($raw, '%_\\').'%');
                if ($digits !== '') {
                    $q->orWhere('phone_number', 'like', '%'.addcslashes($digits, '%_\\').'%');
                }
            });
        }

        $assigned = $validated['assigned'] ?? 'all';
        if ($assigned === 'unassigned') {
            $query->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('phone_number_user')
                    ->whereColumn('phone_number_user.phone_number_id', 'phone_numbers.id')
                    ->where('phone_number_user.status', 'active');
            });
        } elseif ($assigned === 'assigned') {
            $query->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('phone_number_user')
                    ->whereColumn('phone_number_user.phone_number_id', 'phone_numbers.id')
                    ->where('phone_number_user.status', 'active');
            });
        }

        if (! empty($validated['user_id'] ?? null)) {
            $userId = (int) $validated['user_id'];
            $query->whereExists(function ($sub) use ($userId): void {
                $sub->selectRaw('1')
                    ->from('phone_number_user')
                    ->whereColumn('phone_number_user.phone_number_id', 'phone_numbers.id')
                    ->where('phone_number_user.status', 'active')
                    ->where('phone_number_user.user_id', $userId);
            });
        }

        $numbers = $query->latest()->paginate($perPage);

        return $this->success($numbers, 'Phone number assignments fetched.');
    }

    public function carrierNames(): JsonResponse
    {
        $names = PhoneNumber::query()
            ->whereNotNull('carrier_name')
            ->where('carrier_name', '!=', '')
            ->orderBy('id')
            ->pluck('carrier_name')
            ->map(fn (mixed $raw): string => trim((string) $raw))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->sort()
            ->values()
            ->all();

        return $this->success($names, 'Carrier names fetched.');
    }

    public function update(UpdatePhoneNumberRequest $request, PhoneNumber $phoneNumber): JsonResponse
    {
        $phoneNumber->update($request->validated());
        $phoneNumber->load([
            'device:id,device_uid,model,custom_name',
            'users' => function ($query): void {
                $query->wherePivot('status', 'active');
            },
        ]);

        return $this->success($phoneNumber, 'Phone number updated.');
    }

    public function assign(AssignPhoneNumberRequest $request): JsonResponse
    {
        if ($request->filled('phone_number_id')) {
            $phoneNumber = PhoneNumber::query()->findOrFail($request->validated('phone_number_id'));
        } else {
            $phoneNumber = PhoneNumber::findByDialableInput((string) $request->validated('phone_number'));
            if ($phoneNumber === null) {
                throw ValidationException::withMessages([
                    'phone_number' => 'No phone number in the system matches that value.',
                ]);
            }
        }
        $user = User::query()->findOrFail($request->validated('user_id'));
        $order = DB::transaction(function () use ($phoneNumber, $user, $request) {
            $this->phoneNumberService->assignToUser($phoneNumber, $user, $request->user());

            return $this->phoneNumberOrderService->recordAdminProvision($phoneNumber, $user, $request->user());
        });

        return $this->success(
            ['order_id' => $order->id, 'order_status' => $order->status],
            'Phone number assigned successfully.',
        );
    }

    public function unassign(PhoneNumber $phoneNumber): JsonResponse
    {
        $this->phoneNumberService->unassignAllUsers($phoneNumber, request()->user());

        return $this->success(null, 'Phone number unassigned and released for reuse.');
    }

    public function deleteImpact(PhoneNumber $phoneNumber): JsonResponse
    {
        $phoneNumber->loadMissing('device:id,device_uid,model,custom_name');
        $impact = $phoneNumber->deleteImpactCounts();
        $warnings = $this->phoneNumberService->warningsForImpact($impact);

        $activeAssignments = (int) ($impact['active_user_assignments'] ?? 0);

        return $this->success([
            'phone_number_id' => $phoneNumber->id,
            'display_number' => $phoneNumber->phone_number,
            'impact' => $impact,
            'warnings' => $warnings,
            'deletion_blocked' => $activeAssignments > 0,
            'deletion_blocked_reason' => $activeAssignments > 0
                ? 'Unassign all users on Assignments before this line can be deleted.'
                : null,
        ]);
    }

    public function destroy(DeletePhoneNumberRequest $request, PhoneNumber $phoneNumber): JsonResponse
    {
        $this->phoneNumberService->deleteNumber($phoneNumber, $request->user());

        return $this->success(null, 'Phone number deleted.');
    }
}
