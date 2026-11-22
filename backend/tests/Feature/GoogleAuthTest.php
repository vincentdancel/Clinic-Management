<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_returns_error_when_google_is_not_configured(): void
    {
        config()->set('services.google.client_id', null);
        config()->set('services.google.client_secret', null);
        config()->set('app.frontend_url', 'http://localhost:5173');

        $response = $this->get('/auth/google/redirect');

        $response->assertRedirect();
        $this->assertStringStartsWith('http://localhost:5173/login?oauth_error=', (string) $response->headers->get('Location'));
    }

    public function test_callback_signs_in_active_user_matching_google_email(): void
    {
        config()->set('services.google.client_id', 'test-client-id');
        config()->set('services.google.client_secret', 'test-client-secret');
        config()->set('app.frontend_url', 'http://localhost:5173');

        $role = Role::create(['name' => 'Administrator', 'slug' => 'administrator']);
        $user = User::factory()->create([
            'email' => 'vincentdancel692@gmail.com',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getEmail')->andReturn('vincentdancel692@gmail.com');

        Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
        Socialite::shouldReceive('user')->andReturn($socialiteUser);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('http://localhost:5173/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_rejects_unknown_email(): void
    {
        config()->set('services.google.client_id', 'test-client-id');
        config()->set('services.google.client_secret', 'test-client-secret');
        config()->set('app.frontend_url', 'http://localhost:5173');

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getEmail')->andReturn('unknown@gmail.com');

        Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
        Socialite::shouldReceive('user')->andReturn($socialiteUser);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect();
        $this->assertStringStartsWith('http://localhost:5173/login?oauth_error=', (string) $response->headers->get('Location'));
        $this->assertGuest();
    }
}
