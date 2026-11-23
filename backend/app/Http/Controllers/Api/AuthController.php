<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function csrfToken(Request $request): JsonResponse
    {
        return response()->json([
            'csrf_token' => $request->hasSession() ? $request->session()->token() : null,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active || ! Auth::attempt($credentials, $request->boolean('remember'))) {
            return response()->json(['message' => 'The provided credentials are incorrect or the account is inactive.'], 422);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $user->load('role.permissions')]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user()?->currentAccessToken() instanceof PersonalAccessToken) {
            $request->user()->currentAccessToken()->delete();
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
        return response()->json(['user' => $request->user()->load('role.permissions')]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'current_password' => ['required_with:password', 'current_password'],
            'password' => ['sometimes', 'required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user->fill(collect($validated)->only(['name', 'email'])->all());

        if (isset($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        return response()->json(['message' => 'Profile updated.', 'user' => $user->load('role.permissions')]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If an account exists for that address, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => __($status)]);
    }
}
