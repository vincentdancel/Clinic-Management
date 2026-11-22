<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsultationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $doctorUser;
    private User $nurseUser;
    private User $receptionistUser;
    private Employee $doctor;
    private Employee $nurseEmployee;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $viewClinical = Permission::firstOrCreate(['slug' => 'clinical.view'], ['name' => 'View clinical records']);
        $manageClinical = Permission::firstOrCreate(['slug' => 'clinical.manage'], ['name' => 'Manage clinical intake and vitals']);
        $diagnoseClinical = Permission::firstOrCreate(['slug' => 'clinical.diagnose'], ['name' => 'Diagnose and prescribe']);

        $doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
        $doctorRole->permissions()->syncWithoutDetaching([$viewClinical->id, $manageClinical->id, $diagnoseClinical->id]);

        $nurseRole = Role::firstOrCreate(['slug' => 'nurse'], ['name' => 'Nurse']);
        $nurseRole->permissions()->syncWithoutDetaching([$viewClinical->id, $manageClinical->id]);

        $receptionistRole = Role::firstOrCreate(['slug' => 'receptionist'], ['name' => 'Receptionist']);

        $this->doctorUser = User::factory()->create(['role_id' => $doctorRole->id, 'name' => 'Dr. Elena Cruz']);
        $this->nurseUser = User::factory()->create(['role_id' => $nurseRole->id, 'name' => 'Nurse Nina Lopez']);
        $this->receptionistUser = User::factory()->create(['role_id' => $receptionistRole->id, 'name' => 'Receptionist Mia']);

        $department = Department::create([
            'name' => 'Internal Medicine',
            'code' => 'INTMED',
            'is_active' => true,
        ]);

        $this->doctor = Employee::create([
            'user_id' => $this->doctorUser->id,
            'department_id' => $department->id,
            'employee_number' => 'EMP-2026-000010',
            'first_name' => 'Elena',
            'last_name' => 'Cruz',
            'employee_category' => 'doctor',
            'job_title' => 'Attending Physician',
            'hire_date' => '2024-01-10',
            'employment_status' => 'active',
        ]);

        $this->nurseEmployee = Employee::create([
            'user_id' => $this->nurseUser->id,
            'department_id' => $department->id,
            'employee_number' => 'EMP-2026-000011',
            'first_name' => 'Nina',
            'last_name' => 'Lopez',
            'employee_category' => 'nurse',
            'job_title' => 'Clinic Nurse',
            'hire_date' => '2024-02-10',
            'employment_status' => 'active',
        ]);

        $this->patient = Patient::create([
            'patient_number' => 'PAT-2026-000100',
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'gender' => 'male',
            'date_of_birth' => '1990-05-15',
            'phone' => '09171234567',
            'email' => 'alex.rivera@example.test',
        ]);

        $this->patient->allergies()->create([
            'allergen' => 'Penicillin',
            'reaction' => 'Hives and rash',
            'severity' => 'severe',
        ]);

        $this->patient->medicalHistories()->create([
            'condition_name' => 'Hypertension',
            'details' => 'Controlled with lifestyle modification',
            'diagnosed_at' => '2022-03-01',
        ]);
    }

    public function test_complete_consultation_workflow_with_vitals_bmi_prescriptions_and_audit_logs(): void
    {
        $appointment = Appointment::create([
            'appointment_number' => 'APT-2026-000001',
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'created_by' => $this->receptionistUser->id,
            'scheduled_at' => CarbonImmutable::now()->addHour(),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_CONFIRMED,
            'reason' => 'Annual checkup and persistent headache',
        ]);

        // 1. Nurse initiates consultation & records vital signs
        Sanctum::actingAs($this->nurseUser);

        $createRes = $this->postJson('/api/consultations', [
            'appointment_id' => $appointment->id,
            'chief_complaint' => 'Persistent headache for 3 days',
            'symptoms' => 'Throbbing frontal headache, mild photophobia',
            'clinical_notes' => 'Patient alert and oriented.',
        ])->assertCreated();

        $consultationId = $createRes->json('data.id');
        $this->assertMatchesRegularExpression('/^CON-\d{4}-\d{6}$/', $createRes->json('data.consultation_number'));
        $this->assertSame($this->patient->id, $createRes->json('data.patient.id'));
        $this->assertSame($this->doctor->id, $createRes->json('data.doctor.id'));
        $this->assertCount(1, $createRes->json('data.patient.allergies'));
        $this->assertCount(1, $createRes->json('data.patient.medical_histories'));

        // Record vital signs and verify BMI calculation (70kg, 175cm => 22.9 Normal)
        $vitalRes = $this->postJson("/api/consultations/{$consultationId}/vital-signs", [
            'systolic_bp' => 128,
            'diastolic_bp' => 82,
            'temperature_c' => 37.2,
            'heart_rate_bpm' => 76,
            'respiratory_rate' => 16,
            'oxygen_saturation' => 98.5,
            'height_cm' => 175,
            'weight_kg' => 70,
            'notes' => 'Sitting position',
        ])->assertCreated();

        $vitalRes->assertJsonPath('data.blood_pressure', '128/82 mmHg')
            ->assertJsonPath('data.bmi', 22.9)
            ->assertJsonPath('data.bmi_category', 'Normal');

        // 2. Doctor records diagnosis, treatment plan, prescription, and completes consultation
        Sanctum::actingAs($this->doctorUser);

        $this->putJson("/api/consultations/{$consultationId}", [
            'diagnosis' => 'Tension-type headache',
            'treatment_plan' => 'Hydration, rest, stress reduction, and analgesic therapy.',
            'clinical_notes' => 'No focal neurological deficits.',
            'follow_up_date' => CarbonImmutable::now()->addDays(7)->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.diagnosis', 'Tension-type headache');

        $rxRes = $this->postJson("/api/consultations/{$consultationId}/prescriptions", [
            'notes' => 'Take only as needed for pain.',
            'items' => [
                [
                    'medicine_name' => 'Paracetamol 500mg',
                    'dosage' => '500 mg',
                    'frequency' => 'Every 6 hours PRN',
                    'duration' => '5 days',
                    'quantity' => 20,
                    'quantity_unit' => 'tablets',
                    'instructions' => 'Take after meals. Do not exceed 4g/day.',
                ],
            ],
        ])->assertCreated();

        $rxRes->assertJsonPath('data.items.0.medicine_name', 'Paracetamol 500mg')
            ->assertJsonPath('data.items.0.dosage', '500 mg')
            ->assertJsonPath('data.items.0.frequency', 'Every 6 hours PRN')
            ->assertJsonPath('data.items.0.duration', '5 days')
            ->assertJsonPath('data.items.0.quantity', 20);

        $this->postJson("/api/consultations/{$consultationId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(Appointment::STATUS_COMPLETED, $appointment->fresh()->status);

        // 3. View patient consultation history & audit logs
        $this->getJson("/api/consultations/patients/{$this->patient->id}/history")
            ->assertOk()
            ->assertJsonCount(1, 'consultations')
            ->assertJsonPath('consultations.0.diagnosis', 'Tension-type headache');

        $auditRes = $this->getJson("/api/consultations/{$consultationId}/audit-logs")
            ->assertOk();
        $events = collect($auditRes->json('data'))->pluck('event')->all();

        $this->assertContains('consultation.created', $events);
        $this->assertContains('vital_signs.recorded', $events);
        $this->assertContains('consultation.updated', $events);
        $this->assertContains('prescription.created', $events);
        $this->assertContains('consultation.completed', $events);
        $this->assertSame(5, AuditLog::query()->where('auditable_id', $consultationId)->count());
    }

    public function test_receptionist_cannot_access_confidential_clinical_records(): void
    {
        Sanctum::actingAs($this->doctorUser);
        $consultationId = $this->postJson('/api/consultations', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'chief_complaint' => 'Fever',
            'diagnosis' => 'Viral syndrome',
            'treatment_plan' => 'Supportive care',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->receptionistUser);

        $this->getJson('/api/consultations')->assertForbidden();
        $this->getJson("/api/consultations/{$consultationId}")->assertForbidden();
        $this->getJson("/api/consultations/patients/{$this->patient->id}/history")->assertForbidden();
        $this->postJson('/api/consultations', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
        ])->assertForbidden();
        $this->postJson("/api/consultations/{$consultationId}/vital-signs", [
            'temperature_c' => 37.5,
        ])->assertForbidden();
    }

    public function test_nurse_cannot_record_diagnosis_treatment_plan_or_prescriptions(): void
    {
        Sanctum::actingAs($this->nurseUser);

        $this->postJson('/api/consultations', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'chief_complaint' => 'Cough',
            'diagnosis' => 'Acute bronchitis',
        ])->assertForbidden();

        $consultationId = $this->postJson('/api/consultations', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'chief_complaint' => 'Cough',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/consultations/{$consultationId}", [
            'treatment_plan' => 'Prescribe antibiotics',
        ])->assertForbidden();

        $this->postJson("/api/consultations/{$consultationId}/prescriptions", [
            'items' => [[
                'medicine_name' => 'Amoxicillin',
                'dosage' => '500 mg',
                'frequency' => 'TID',
                'quantity' => 21,
            ]],
        ])->assertForbidden();

        $this->postJson("/api/consultations/{$consultationId}/complete")->assertForbidden();
    }

    public function test_validation_rejects_invalid_doctor_patient_and_vitals(): void
    {
        Sanctum::actingAs($this->doctorUser);

        // Nurse employee cannot be assigned as responsible doctor
        $this->postJson('/api/consultations', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->nurseEmployee->id,
            'chief_complaint' => 'Checkup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['doctor_id']);

        $consultationId = $this->postJson('/api/consultations', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'chief_complaint' => 'Checkup',
        ])->assertCreated()->json('data.id');

        // Systolic BP must be greater than diastolic BP
        $this->postJson("/api/consultations/{$consultationId}/vital-signs", [
            'systolic_bp' => 80,
            'diastolic_bp' => 120,
        ])->assertUnprocessable()->assertJsonValidationErrors(['systolic_bp']);

        // Empty vital signs payload rejected
        $this->postJson("/api/consultations/{$consultationId}/vital-signs", [
            'notes' => 'Just a note',
        ])->assertUnprocessable()->assertJsonValidationErrors(['vital_signs']);
    }
}

