<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'receptionist'], ['name' => 'Receptionist']);
        $viewPermission = Permission::firstOrCreate(['slug' => 'patients.view'], ['name' => 'View patients']);
        $managePermission = Permission::firstOrCreate(['slug' => 'patients.manage'], ['name' => 'Manage patients']);
        $role->permissions()->attach([$viewPermission->id, $managePermission->id]);
        $this->user = User::factory()->create(['role_id' => $role->id]);
        Sanctum::actingAs($this->user);
    }

    public function test_patient_registration_generates_unique_numbers_and_patient_can_be_found_in_search(): void
    {
        $first = $this->postJson('/api/patients', $this->patientPayload())->assertCreated();
        $second = $this->postJson('/api/patients', $this->patientPayload([
            'first_name' => 'Mina',
            'last_name' => 'Santos',
            'email' => 'mina@example.test',
        ]))->assertCreated();

        $firstNumber = $first->json('data.patient_number');
        $secondNumber = $second->json('data.patient_number');

        $this->assertNotSame($firstNumber, $secondNumber);
        $this->assertMatchesRegularExpression('/^PAT-\d{4}-\d{6}$/', $firstNumber);

        $this->getJson('/api/patients?search=mina')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Mina Santos');
    }

    public function test_patient_profile_can_be_viewed_updated_and_archived(): void
    {
        $patientId = $this->postJson('/api/patients', $this->patientPayload())
            ->assertCreated()
            ->json('data.id');

        $this->getJson('/api/patients/'.$patientId)
            ->assertOk()
            ->assertJsonPath('data.email', 'alex@example.test')
            ->assertJsonPath('data.age', now()->parse('1990-02-12')->age);

        $this->putJson('/api/patients/'.$patientId, [
            'first_name' => 'Alexandra',
            'phone' => '09170000000',
            'address' => 'Updated address',
        ])->assertOk()->assertJsonPath('data.full_name', 'Alexandra Rivera');

        $this->deleteJson('/api/patients/'.$patientId)
            ->assertOk()
            ->assertJsonPath('message', 'Patient record archived.');

        $this->assertSoftDeleted('patients', ['id' => $patientId]);
        $this->getJson('/api/patients?status=active')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/patients?status=archived')->assertOk()->assertJsonPath('data.0.status', 'archived');
        $this->getJson('/api/patients/'.$patientId)->assertOk()->assertJsonPath('data.status', 'archived');
        $this->putJson('/api/patients/'.$patientId, ['first_name' => 'Should Not Update'])->assertNotFound();
    }

    public function test_allergies_and_medical_history_can_be_created_updated_and_removed(): void
    {
        $patientId = $this->postJson('/api/patients', $this->patientPayload())->assertCreated()->json('data.id');

        $allergyId = $this->postJson('/api/patients/'.$patientId.'/allergies', [
            'allergen' => 'Peanuts',
            'reaction' => 'Hives',
            'severity' => 'moderate',
        ])->assertCreated()->assertJsonPath('data.allergen', 'Peanuts')->json('data.id');

        $historyId = $this->postJson('/api/patients/'.$patientId.'/medical-history', [
            'condition_name' => 'Asthma',
            'details' => 'Uses an inhaler as needed.',
            'diagnosed_at' => '2018-06-01',
        ])->assertCreated()->assertJsonPath('data.recorded_by', $this->user->id)->json('data.id');

        $this->putJson('/api/patients/'.$patientId.'/medical-history/'.$historyId, [
            'condition_name' => 'Asthma',
            'details' => 'Reviewed at annual visit.',
            'diagnosed_at' => '2018-06-01',
            'resolved_at' => null,
        ])->assertOk()->assertJsonPath('data.details', 'Reviewed at annual visit.');

        $this->getJson('/api/patients/'.$patientId)
            ->assertOk()
            ->assertJsonPath('data.allergies.0.allergen', 'Peanuts')
            ->assertJsonPath('data.medical_histories.0.condition_name', 'Asthma');

        $this->deleteJson('/api/patients/'.$patientId.'/allergies/'.$allergyId)->assertOk();
        $this->deleteJson('/api/patients/'.$patientId.'/medical-history/'.$historyId)->assertOk();
        $this->getJson('/api/patients/'.$patientId)
            ->assertOk()
            ->assertJsonCount(0, 'data.allergies')
            ->assertJsonCount(0, 'data.medical_histories');
    }

    public function test_patient_validation_and_permissions_are_enforced(): void
    {
        $this->postJson('/api/patients', ['first_name' => '', 'last_name' => 'Rivera', 'email' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'email']);

        $this->postJson('/api/patients', $this->patientPayload())->assertCreated();
        $this->postJson('/api/patients', $this->patientPayload())->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $viewOnlyRole = Role::create(['name' => 'Billing clerk', 'slug' => 'billing-clerk']);
        $viewPermission = Permission::where('slug', 'patients.view')->firstOrFail();
        $viewOnlyRole->permissions()->attach($viewPermission);
        $viewOnlyUser = User::factory()->create(['role_id' => $viewOnlyRole->id]);
        Sanctum::actingAs($viewOnlyUser);

        $this->getJson('/api/patients')->assertOk();
        $this->postJson('/api/patients', $this->patientPayload(['email' => 'another@example.test']))->assertForbidden();
    }

    public function test_patient_endpoints_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/patients')->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function patientPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'gender' => 'female',
            'date_of_birth' => '1990-02-12',
            'phone' => '09171112222',
            'email' => 'alex@example.test',
            'address' => '123 Clinic Road',
            'emergency_contact_name' => 'Jamie Rivera',
            'emergency_contact_phone' => '09173334444',
        ], $overrides);
    }
}
