<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientPortalProfileResource;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class PatientPortalAuthController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower(trim($credentials['email']));
        $user = User::query()->with(['role.permissions', 'patient'])->where('email', $email)->first();

        if (! $user || ! $user->is_active || ! Auth::attempt(['email' => $email, 'password' => $credentials['password']], $request->boolean('remember'))) {
            return response()->json(['message' => 'The provided credentials are incorrect or the account is inactive.'], 422);
        }

        if (! $user->isPatient()) {
            Auth::guard('web')->logout();

            return response()->json(['message' => 'Staff accounts must sign in through the Staff Portal.'], 403);
        }

        if (! $user->patient || $user->patient->trashed()) {
            Auth::guard('web')->logout();

            return response()->json(['message' => 'This account is not linked to an active patient record.'], 403);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->auditLogs->log('patient.login', $user->patient, $user, null, ['user_id' => $user->id], $request);

        return response()->json([
            'user' => $user,
            'patient' => new PatientPortalProfileResource($user->patient),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user?->patient) {
            $this->auditLogs->log('patient.logout', $user->patient, $user, null, ['user_id' => $user->id], $request);
        }

        if ($user?->currentAccessToken() instanceof PersonalAccessToken) {
            $user->currentAccessToken()->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'You have been signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role.permissions', 'patient']);

        return response()->json([
            'user' => $user,
            'patient' => new PatientPortalProfileResource($user->patient),
        ]);
    }
}
