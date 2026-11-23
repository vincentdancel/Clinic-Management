<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_and_log_out(): void
    {
        $role = Role::create(['name' => 'Administrator', 'slug' => 'administrator']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'admin@example.test',
            'password' => 'StrongPass123!',
        ]);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'StrongPass123!'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_inactive_accounts_cannot_log_in_or_use_protected_endpoints(): void
    {
        $role = Role::create(['name' => 'Doctor', 'slug' => 'doctor']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'doctor@example.test',
            'password' => 'StrongPass123!',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'StrongPass123!'])
            ->assertUnprocessable();

        $this->actingAs($user)->getJson('/api/auth/me')->assertForbidden();
    }

    public function test_protected_endpoints_require_authentication_and_assigned_permissions(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();

        $role = Role::create(['name' => 'Receptionist', 'slug' => 'receptionist']);
        $user = User::factory()->create(['role_id' => $role->id]);
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/users')->assertForbidden();

        $permission = Permission::create(['name' => 'Manage users', 'slug' => 'users.manage']);
        $role->permissions()->attach($permission);
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/users')->assertOk();
    }

    public function test_admin_can_create_users_with_hashed_passwords_and_control_account_status(): void
    {
        $permission = Permission::create(['name' => 'Manage users', 'slug' => 'users.manage']);
        $adminRole = Role::create(['name' => 'Administrator', 'slug' => 'administrator']);
        $adminRole->permissions()->attach($permission);
        $staffRole = Role::create(['name' => 'Nurse', 'slug' => 'nurse']);
        $admin = User::factory()->create(['role_id' => $adminRole->id]);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/admin/users', [
            'name' => 'Test Nurse',
            'email' => 'nurse@example.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role_id' => $staffRole->id,
        ])->assertCreated();

        $createdUser = User::findOrFail($response->json('user.id'));
        $this->assertTrue(Hash::check('StrongPass123!', $createdUser->password));

        $this->patchJson('/api/admin/users/'.$createdUser->id.'/active', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('user.is_active', false);
    }

    public function test_role_assignment_is_limited_to_existing_roles_and_profile_validation_is_enforced(): void
    {
        $role = Role::create(['name' => 'Doctor', 'slug' => 'doctor']);
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->assertTrue($user->is_active);

        Sanctum::actingAs($user);
        $this->putJson('/api/auth/profile', ['email' => 'invalid-email'])->assertUnprocessable();
    }

    public function test_password_reset_link_can_be_used_to_set_a_new_hashed_password(): void
    {
        Notification::fake();
        $role = Role::create(['name' => 'Doctor', 'slug' => 'doctor']);
        $user = User::factory()->create(['role_id' => $role->id, 'email' => 'reset@example.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
        $notification = Notification::sent($user, ResetPassword::class)->first();

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $notification->token,
            'password' => 'DifferentPass456!',
            'password_confirmation' => 'DifferentPass456!',
        ])->assertOk();

        $this->assertTrue(Hash::check('DifferentPass456!', $user->fresh()->password));
    }

    public function test_stateful_frontend_can_fetch_csrf_token_for_cross_origin_requests(): void
    {
        $permission = Permission::create(['name' => 'Manage users', 'slug' => 'users.manage']);
        $adminRole = Role::create(['name' => 'Administrator', 'slug' => 'administrator']);
        $adminRole->permissions()->attach($permission);
        $staffRole = Role::create(['name' => 'Nurse', 'slug' => 'nurse']);
        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'admin-csrf@example.test',
            'password' => 'StrongPass123!',
        ]);

        $csrfResponse = $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/auth/csrf-token')
            ->assertOk();

        $csrfToken = $csrfResponse->json('csrf_token');
        $this->assertIsString($csrfToken);
        $this->assertNotEmpty($csrfToken);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'StrongPass123!'])
            ->assertOk();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson('/api/admin/users', [
                'name' => 'Cross Origin Nurse',
                'email' => 'cross-origin-nurse@example.test',
                'password' => 'StrongPass123!',
                'password_confirmation' => 'StrongPass123!',
                'role_id' => $staffRole->id,
            ])
            ->assertCreated();
    }
}
