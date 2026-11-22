<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return $this->redirectWithError('Google sign-in is not configured yet. Please set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.');
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return $this->redirectWithError('Google sign-in is not configured yet. Please set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return $this->redirectWithError('Unable to complete Google sign-in. Please try again.');
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));
        $user = $email !== '' ? User::whereRaw('LOWER(email) = ?', [$email])->first() : null;

        if (! $user || ! $user->is_active) {
            return $this->redirectWithError('No active clinic account found for this Google email. Please contact your administrator.');
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->away($this->frontendUrl().'/');
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    private function frontendUrl(): string
    {
        return rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
    }

    private function redirectWithError(string $message): RedirectResponse
    {
        return redirect()->away($this->frontendUrl().'/login?oauth_error='.urlencode($message));
    }
}
