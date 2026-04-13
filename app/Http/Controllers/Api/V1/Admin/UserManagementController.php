<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $perPage = (int) request()->input('per_page', 20);
        $perPage = max(5, min($perPage, 200));

        $query = User::query()->latest();
        if (request()->boolean('assignment_only')) {
            $query->where('role', 'user');
        }

        return response()->json(['data' => $query->paginate($perPage)]);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['password'] = Hash::make($payload['password']);
        $payload['status'] = $payload['status'] ?? 'active';
        $user = User::create($payload);

        return response()->json(['data' => $user], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $payload = $request->validated();
        $authUser = $request->user();

        if ($authUser && $authUser->id === $user->id && $payload['role'] !== 'admin') {
            return response()->json(['message' => 'You cannot remove your own admin role.'], 422);
        }

        if (! empty($payload['password'])) {
            $payload['password'] = Hash::make($payload['password']);
        } else {
            unset($payload['password']);
        }

        $user->update($payload);

        return response()->json(['data' => $user->fresh()]);
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): JsonResponse
    {
        $user->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function toggleStatus(User $user): JsonResponse
    {
        $authUser = request()->user();
        if ($authUser && $authUser->id === $user->id) {
            return response()->json(['message' => 'You cannot block your own account.'], 422);
        }

        $nextStatus = $user->status === 'blocked' ? 'active' : 'blocked';
        $user->update(['status' => $nextStatus]);

        return response()->json(['data' => $user->fresh(), 'message' => 'User status updated.']);
    }

    public function destroy(User $user): JsonResponse
    {
        $authUser = request()->user();
        if ($authUser && $authUser->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }
}
