<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();

        $managePerm = Permission::firstOrCreate(
            ['slug' => 'settings.manage'],
            ['name' => 'Manage clinic settings', 'description' => 'Manage clinic settings']
        );

        $adminRole = Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $adminRole->permissions()->syncWithoutDetaching([$managePerm->id]);

        $staffRole = Role::firstOrCreate(['slug' => 'receptionist'], ['name' => 'Receptionist']);

        $this->adminUser = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        $this->staffUser = User::factory()->create(['role_id' => $staffRole->id, 'is_active' => true]);
    }

    public function test_authenticated_users_can_view_default_clinic_settings(): void
    {
        Sanctum::actingAs($this->staffUser);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.clinic_name', 'Clinic Care Medical Center')
            ->assertJsonPath('settings.currency_code', 'PHP')
            ->assertJsonPath('settings.appointment_duration_minutes', 30);
    }

    public function test_users_without_permission_cannot_update_clinic_settings(): void
    {
        Sanctum::actingAs($this->staffUser);

        $this->putJson('/api/settings', [
            'clinic_name' => 'Updated Clinic',
            'opening_time' => '08:00',
            'closing_time' => '17:00',
            'appointment_duration_minutes' => 30,
            'working_days' => ['mon', 'tue'],
            'allow_walk_ins' => true,
            'currency_code' => 'PHP',
            'tax_rate_percent' => 0,
            'invoice_prefix' => 'INV-',
            'low_stock_threshold' => 10,
            'appointment_reminders_enabled' => true,
            'low_stock_alerts_enabled' => true,
        ])->assertForbidden();
    }

    public function test_administrator_can_update_and_persist_clinic_settings(): void
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'clinic_name' => 'CarePlus Outpatient Clinic',
            'clinic_tagline' => 'Compassionate Care Daily',
            'clinic_email' => 'hello@careplus.local',
            'clinic_phone' => '+63 2 8999 0000',
            'clinic_address' => '456 Wellness Blvd, Quezon City',
            'clinic_website' => 'https://careplus.local',
            'tax_id' => 'TIN-123-456-789',
            'opening_time' => '07:30',
            'closing_time' => '18:30',
            'appointment_duration_minutes' => 20,
            'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'allow_walk_ins' => false,
            'currency_code' => 'USD',
            'tax_rate_percent' => 12,
            'invoice_prefix' => 'CP-',
            'low_stock_threshold' => 25,
            'appointment_reminders_enabled' => false,
            'low_stock_alerts_enabled' => true,
        ];

        $this->putJson('/api/settings', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Clinic settings updated.')
            ->assertJsonPath('settings.clinic_name', 'CarePlus Outpatient Clinic')
            ->assertJsonPath('settings.appointment_duration_minutes', 20)
            ->assertJsonPath('settings.allow_walk_ins', false)
            ->assertJsonPath('settings.currency_code', 'USD')
            ->assertJsonPath('settings.tax_rate_percent', 12)
            ->assertJsonPath('settings.working_days', ['mon', 'tue', 'wed', 'thu', 'fri']);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.clinic_name', 'CarePlus Outpatient Clinic')
            ->assertJsonPath('settings.invoice_prefix', 'CP-');
    }

    public function test_invalid_closing_time_is_rejected(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->putJson('/api/settings', [
            'clinic_name' => 'Clinic Care Medical Center',
            'opening_time' => '17:00',
            'closing_time' => '08:00',
            'appointment_duration_minutes' => 30,
            'working_days' => ['mon'],
            'allow_walk_ins' => true,
            'currency_code' => 'PHP',
            'tax_rate_percent' => 0,
            'invoice_prefix' => 'INV-',
            'low_stock_threshold' => 10,
            'appointment_reminders_enabled' => true,
            'low_stock_alerts_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['closing_time']);
    }
}
