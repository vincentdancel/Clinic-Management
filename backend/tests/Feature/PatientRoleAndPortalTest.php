<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientRoleAndPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_ordinary_patient_registration_does_not_create_user_account(): void
    {
        $admin = $this->createStaffUser('administrator', 'admin@clinic.test');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/patients', [
            'first_name' => 'Elena',
            'last_name' => 'Reyes',
            'email' => 'elena.reyes@example.com',
            'phone' => '09171234567',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.has_portal_account', false);

        $patient = Patient::query()->where('email', 'elena.reyes@example.com')->firstOrFail();
        $this->assertNull($patient->user_id);
        $this->assertDatabaseMissing('users', ['email' => 'elena.reyes@example.com']);
    }

    public function test_administrator_can_create_and_link_patient_account_without_exposing_password(): void
    {
        $admin = $this->createStaffUser('administrator', 'admin@clinic.test');
        $patient = $this->createPatientRecord('PAT-2026-000001', 'Marco', 'Santos', 'marco@example.com');

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/patients/{$patient->id}/account", [
            'email' => 'marco.portal@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('portal_account.email', 'marco.portal@example.com')
            ->assertJsonMissingPath('portal_account.password');

        $user = User::query()->where('email', 'marco.portal@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('StrongPass123!', $user->password));
        $this->assertSame($patient->id, $user->patient_id);
        $this->assertNull($user->employee_id);
        $this->assertSame($user->id, $patient->fresh()->user_id);

        // Duplicate account creation for the same patient must fail.
        $this->postJson("/api/patients/{$patient->id}/account", [
            'email' => 'marco.second@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ])->assertStatus(422);
    }

    public function test_administrator_can_link_existing_unlinked_patient_user(): void
    {
        $admin = $this->createStaffUser('administrator', 'admin@clinic.test');
        $patientRole = Role::query()->where('slug', 'patient')->firstOrFail();
        $patient = $this->createPatientRecord('PAT-2026-000002', 'Liza', 'Cruz', 'liza@example.com');

        $unlinkedUser = User::create([
            'role_id' => $patientRole->id,
            'name' => 'Liza Cruz',
            'email' => 'liza.user@example.com',
            'password' => 'StrongPass123!',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/patients/{$patient->id}/link-account", [
            'user_id' => $unlinkedUser->id,
        ])->assertOk()
            ->assertJsonPath('portal_account.patient_id', $patient->id);

        $this->assertSame($patient->id, $unlinkedUser->fresh()->patient_id);
        $this->assertSame($unlinkedUser->id, $patient->fresh()->user_id);
    }

    public function test_unlinked_patient_user_cannot_authenticate_as_patient(): void
    {
        $patientRole = Role::query()->where('slug', 'patient')->firstOrFail();
        User::create([
            'role_id' => $patientRole->id,
            'name' => 'Unlinked User',
            'email' => 'unlinked@example.com',
            'password' => 'StrongPass123!',
            'is_active' => true,
        ]);

        $this->postJson('/api/patient/login', [
            'email' => 'unlinked@example.com',
            'password' => 'StrongPass123!',
        ])->assertStatus(403);
    }

    public function test_patient_can_login_and_access_own_profile_appointments_and_records(): void
    {
        [$patientUser, $patient] = $this->createLinkedPatient('PAT-2026-000003', 'Ana', 'Lim', 'ana@example.com');
        $doctor = $this->createDoctorEmployee('DOC-001', 'Gregory', 'House');

        $upcoming = Appointment::create([
            'appointment_number' => 'APT-2026-0001',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->addDays(2),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_CONFIRMED,
            'reason' => 'Follow-up checkup',
        ]);

        $pastAppointment = Appointment::create([
            'appointment_number' => 'APT-2026-0002',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->subDays(3),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_COMPLETED,
            'reason' => 'Initial consultation',
        ]);

        $releasedConsultation = Consultation::create([
            'consultation_number' => 'CON-2026-0001',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_id' => $pastAppointment->id,
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDays(3)->addMinutes(25),
            'chief_complaint' => 'Headache',
            'diagnosis' => 'Tension headache',
            'treatment_plan' => 'Hydration and rest',
            'status' => Consultation::STATUS_COMPLETED,
            'is_released_to_patient' => true,
            'released_to_patient_at' => now()->subDays(2),
        ]);

        Prescription::create([
            'consultation_id' => $releasedConsultation->id,
            'prescribed_by' => $doctor->id,
            'prescribed_at' => now()->subDays(3)->toDateString(),
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'patient_id' => $patient->id,
            'appointment_id' => $pastAppointment->id,
            'consultation_id' => $releasedConsultation->id,
            'invoice_number' => 'INV-2026-0001',
            'issued_at' => now()->subDays(3)->toDateString(),
            'subtotal' => 500,
            'total_amount' => 500,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        Payment::create([
            'invoice_id' => $invoice->id,
            'receipt_number' => 'RCP-2026-0001',
            'amount' => 200,
            'method' => Payment::METHOD_CASH,
            'paid_at' => now()->subDays(2),
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $this->postJson('/api/patient/login', [
            'email' => 'ana@example.com',
            'password' => 'StrongPass123!',
        ])->assertOk()
            ->assertJsonPath('patient.patient_number', 'PAT-2026-000003');

        Sanctum::actingAs($patientUser);

        $this->getJson('/api/patient/profile')
            ->assertOk()
            ->assertJsonPath('data.patient_number', 'PAT-2026-000003');

        $this->putJson('/api/patient/profile', [
            'phone' => '09998887777',
            'address' => '456 Wellness St.',
        ])->assertOk()
            ->assertJsonPath('data.phone', '09998887777');

        $this->getJson('/api/patient/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'upcoming')
            ->assertJsonPath('upcoming.0.id', $upcoming->id)
            ->assertJsonCount(1, 'history');

        $this->getJson('/api/patient/dashboard')
            ->assertOk()
            ->assertJsonPath('patient.patient_number', 'PAT-2026-000003')
            ->assertJsonCount(1, 'upcoming_appointments')
            ->assertJsonCount(1, 'recent_consultations')
            ->assertJsonCount(1, 'active_prescriptions')
            ->assertJsonCount(1, 'outstanding_invoices')
            ->assertJsonCount(1, 'recent_payments');
    }

    public function test_patient_cannot_access_other_patients_or_unreleased_records_or_staff_modules(): void
    {
        [$patientUser, $patient] = $this->createLinkedPatient('PAT-2026-000004', 'Ben', 'Tan', 'ben@example.com');
        [, $otherPatient] = $this->createLinkedPatient('PAT-2026-000005', 'Carla', 'Go', 'carla@example.com');
        $doctor = $this->createDoctorEmployee('DOC-002', 'Meredith', 'Grey');

        $otherAppointment = Appointment::create([
            'appointment_number' => 'APT-2026-0009',
            'patient_id' => $otherPatient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->subDay(),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_COMPLETED,
        ]);

        $otherConsultation = Consultation::create([
            'consultation_number' => 'CON-2026-0009',
            'patient_id' => $otherPatient->id,
            'doctor_id' => $doctor->id,
            'appointment_id' => $otherAppointment->id,
            'started_at' => now()->subDay(),
            'diagnosis' => 'Confidential diagnosis',
            'status' => Consultation::STATUS_COMPLETED,
            'is_released_to_patient' => true,
        ]);

        $ownUnreleasedAppointment = Appointment::create([
            'appointment_number' => 'APT-2026-0010',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->subDay(),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_COMPLETED,
        ]);

        $unreleasedConsultation = Consultation::create([
            'consultation_number' => 'CON-2026-0010',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_id' => $ownUnreleasedAppointment->id,
            'started_at' => now()->subDay(),
            'diagnosis' => 'Pending review',
            'status' => Consultation::STATUS_COMPLETED,
            'is_released_to_patient' => false,
        ]);

        $otherInvoice = Invoice::create([
            'patient_id' => $otherPatient->id,
            'invoice_number' => 'INV-2026-0009',
            'issued_at' => now()->toDateString(),
            'subtotal' => 900,
            'total_amount' => 900,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        Sanctum::actingAs($patientUser);

        // Cannot access staff endpoints
        $this->getJson('/api/dashboard')->assertForbidden();
        $this->getJson('/api/search?q=Carla')->assertForbidden();
        $this->getJson("/api/patients/{$otherPatient->id}")->assertForbidden();
        $this->getJson('/api/inventory/medicines')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/reports/financial')->assertForbidden();

        // Cannot modify medical diagnoses
        $this->putJson("/api/consultations/{$unreleasedConsultation->id}", [
            'diagnosis' => 'Tampered diagnosis',
        ])->assertForbidden();

        // Cannot view unreleased consultation or another patient's consultation/invoice
        $this->getJson("/api/patient/consultations/{$unreleasedConsultation->id}")->assertForbidden();
        $this->getJson("/api/patient/consultations/{$otherConsultation->id}")->assertForbidden();
        $this->getJson("/api/patient/invoices/{$otherInvoice->id}")->assertForbidden();
    }

    public function test_staff_accounts_still_log_in_normally_and_cannot_use_patient_login(): void
    {
        $staff = $this->createStaffUser('doctor', 'doctor@clinic.test');

        $this->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/auth/login', [
                'email' => 'doctor@clinic.test',
                'password' => 'StrongPass123!',
            ])->assertOk()
            ->assertJsonPath('user.id', $staff->id);

        $this->withHeader('Referer', 'http://localhost:5173')
            ->postJson('/api/patient/login', [
                'email' => 'doctor@clinic.test',
                'password' => 'StrongPass123!',
            ])->assertForbidden();
    }

    private function createStaffUser(string $roleSlug, string $email): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::create([
            'role_id' => $role->id,
            'name' => ucfirst($roleSlug).' User',
            'email' => $email,
            'password' => 'StrongPass123!',
            'is_active' => true,
        ]);
    }

    private function createPatientRecord(string $number, string $firstName, string $lastName, string $email): Patient
    {
        return Patient::create([
            'patient_number' => $number,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => '09170000000',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0: User, 1: Patient}
     */
    private function createLinkedPatient(string $number, string $firstName, string $lastName, string $email): array
    {
        $patientRole = Role::query()->where('slug', 'patient')->firstOrFail();
        $patient = $this->createPatientRecord($number, $firstName, $lastName, $email);

        $user = User::create([
            'role_id' => $patientRole->id,
            'patient_id' => $patient->id,
            'name' => "{$firstName} {$lastName}",
            'email' => $email,
            'password' => 'StrongPass123!',
            'is_active' => true,
        ]);

        $patient->update(['user_id' => $user->id]);

        return [$user->load('role.permissions'), $patient->fresh()];
    }

    private function createDoctorEmployee(string $number, string $firstName, string $lastName): Employee
    {
        $department = Department::firstOrCreate(
            ['code' => 'MED'],
            ['name' => 'General Medicine', 'is_active' => true],
        );

        return Employee::create([
            'department_id' => $department->id,
            'employee_number' => $number,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'job_title' => 'Physician',
            'hire_date' => now()->subYear()->toDateString(),
            'employment_status' => 'active',
        ]);
    }
}
