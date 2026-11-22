<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['users' => User::with('role')->orderBy('name')->paginate(20)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $user = User::create($validated);

        return response()->json(['message' => 'User account created.', 'user' => $user->load('role')], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'role_id' => ['sometimes', 'required', 'integer', 'exists:roles,id'],
        ]);

        $user->update($validated);

        return response()->json(['message' => 'User account updated.', 'user' => $user->load('role')]);
    }

    public function setActive(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        if (! $validated['is_active'] && $request->user()->is($user)) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        if (! $validated['is_active'] && $user->role?->slug === 'administrator' && $user->is_active) {
            $activeAdministrators = User::where('is_active', true)
                ->whereHas('role', fn ($query) => $query->where('slug', 'administrator'))
                ->count();

            if ($activeAdministrators <= 1) {
                return response()->json(['message' => 'At least one active administrator must remain.'], 422);
            }
        }

        $user->update(['is_active' => $validated['is_active']]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json(['message' => $user->is_active ? 'Account activated.' : 'Account deactivated.', 'user' => $user->load('role')]);
    }
}
