<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_returns_matching_records_according_to_permissions(): void
    {
        $patientsPerm = Permission::firstOrCreate(['slug' => 'patients.view'], ['name' => 'View patients']);
        $role = Role::firstOrCreate(['slug' => 'receptionist'], ['name' => 'Receptionist']);
        $role->permissions()->syncWithoutDetaching([$patientsPerm->id]);

        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $patient = Patient::create([
            'patient_number' => 'PT-9001',
            'first_name' => 'Elena',
            'last_name' => 'Santos',
            'gender' => 'female',
            'date_of_birth' => '1992-05-14',
            'phone' => '09171234567',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/search?q=Elena')
            ->assertOk()
            ->assertJsonCount(1, 'patients')
            ->assertJsonPath('patients.0.id', $patient->id)
            ->assertJsonPath('patients.0.to', "/patients/{$patient->id}")
            ->assertJsonCount(0, 'invoices');
    }
}
