<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DoctorSchedule;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Department $department;
    private Employee $doctor;
    private Employee $otherDoctor;
    private Employee $nurse;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'receptionist'], ['name' => 'Receptionist']);
        $viewPermission = Permission::firstOrCreate(['slug' => 'appointments.view'], ['name' => 'View appointments']);
        $managePermission = Permission::firstOrCreate(['slug' => 'appointments.manage'], ['name' => 'Manage appointments']);
        $role->permissions()->syncWithoutDetaching([$viewPermission->id, $managePermission->id]);

        $this->user = User::factory()->create(['role_id' => $role->id]);
        Sanctum::actingAs($this->user);

        $this->department = Department::create([
            'name' => 'General Medicine',
            'code' => 'GENMED',
            'is_active' => true,
        ]);

        $this->doctor = Employee::create([
            'department_id' => $this->department->id,
            'employee_number' => 'EMP-2026-000001',
            'first_name' => 'Elena',
            'last_name' => 'Cruz',
            'employee_category' => 'doctor',
            'job_title' => 'Attending Physician',
            'hire_date' => '2024-01-10',
            'employment_status' => 'active',
        ]);

        $this->otherDoctor = Employee::create([
            'department_id' => $this->department->id,
            'employee_number' => 'EMP-2026-000002',
            'first_name' => 'Marco',
            'last_name' => 'Reyes',
            'employee_category' => 'doctor',
            'job_title' => 'Consultant Physician',
            'hire_date' => '2024-02-10',
            'employment_status' => 'active',
        ]);

        $this->nurse = Employee::create([
            'department_id' => $this->department->id,
            'employee_number' => 'EMP-2026-000003',
            'first_name' => 'Nina',
            'last_name' => 'Lopez',
            'employee_category' => 'nurse',
            'job_title' => 'Clinic Nurse',
            'hire_date' => '2024-03-10',
            'employment_status' => 'active',
        ]);

        $this->patient = Patient::create([
            'patient_number' => 'PAT-2026-000001',
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'phone' => '09171234567',
        ]);
    }

    public function test_appointment_creation_connects_active_patient_and_doctor_and_records_history(): void
    {
        $targetDate = CarbonImmutable::now()->addDays(2)->format('Y-m-d');

        $response = $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $targetDate,
            'appointment_time' => '09:30',
            'duration_minutes' => 30,
            'reason' => 'Annual physical checkup',
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.patient.id', $this->patient->id)
            ->assertJsonPath('data.doctor.id', $this->doctor->id)
            ->assertJsonPath('data.reason', 'Annual physical checkup')
            ->assertJsonPath('data.histories.0.action', 'created')
            ->assertJsonPath('data.histories.0.to_status', 'pending');

        $this->assertMatchesRegularExpression('/^APT-\d{4}-\d{6}$/', $response->json('data.appointment_number'));

        // Non-doctor employee cannot be assigned as doctor
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->nurse->id,
            'appointment_date' => $targetDate,
            'appointment_time' => '11:00',
            'reason' => 'Consultation',
        ])->assertUnprocessable()->assertJsonValidationErrors(['doctor_id']);

        // Archived patient cannot be booked
        $this->patient->delete();
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $targetDate,
            'appointment_time' => '11:30',
            'reason' => 'Consultation',
        ])->assertUnprocessable()->assertJsonValidationErrors(['patient_id']);
    }

    public function test_conflicting_doctor_schedules_are_prevented(): void
    {
        $targetDate = CarbonImmutable::now()->addDays(3)->format('Y-m-d');

        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $targetDate,
            'appointment_time' => '10:00',
            'duration_minutes' => 30,
            'reason' => 'Initial consultation',
        ])->assertCreated();

        // Exact same time conflicts
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $targetDate,
            'appointment_time' => '10:00',
            'duration_minutes' => 30,
            'reason' => 'Overlapping slot',
        ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);

        // Partially overlapping time (10:15 - 10:45 overlaps 10:00 - 10:30) conflicts
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $targetDate,
            'appointment_time' => '10:15',
            'duration_minutes' => 30,
            'reason' => 'Partial overlap',
        ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);

        // Adjacent slot (10:30 - 11:00) succeeds
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $targetDate,
            'appointment_time' => '10:30',
            'duration_minutes' => 30,
            'reason' => 'Adjacent follow-up',
        ])->assertCreated();

        // Different doctor at 10:00 succeeds
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->otherDoctor->id,
            'appointment_date' => $targetDate,
            'appointment_time' => '10:00',
            'duration_minutes' => 30,
            'reason' => 'Second opinion',
        ])->assertCreated();
    }

    public function test_doctor_schedule_window_is_enforced_and_available_slots_reflect_bookings(): void
    {
        $targetDate = CarbonImmutable::now()->addDays(4);
        $dayOfWeek = $targetDate->dayOfWeek;

        DoctorSchedule::create([
            'employee_id' => $this->doctor->id,
            'day_of_week' => $dayOfWeek,
            'starts_at' => '09:00:00',
            'ends_at' => '12:00:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ]);

        // Outside schedule (14:00) is rejected
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $targetDate->format('Y-m-d'),
            'appointment_time' => '14:00',
            'duration_minutes' => 30,
            'reason' => 'Afternoon visit',
        ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);

        // Inside schedule (09:30) succeeds
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $targetDate->format('Y-m-d'),
            'appointment_time' => '09:30',
            'duration_minutes' => 30,
            'reason' => 'Morning checkup',
        ])->assertCreated();

        $slotsResponse = $this->getJson('/api/appointments/available-slots?doctor_id='.$this->doctor->id.'&date='.$targetDate->format('Y-m-d'))
            ->assertOk();

        $slots = collect($slotsResponse->json('slots'));
        $slot0900 = $slots->firstWhere('time', '09:00');
        $slot0930 = $slots->firstWhere('time', '09:30');

        $this->assertTrue($slot0900['available']);
        $this->assertFalse($slot0930['available']);
        $this->assertSame('Booked', $slot0930['reason']);
    }

    public function test_appointment_can_be_rescheduled_and_conflicts_on_new_slot_are_blocked(): void
    {
        $date = CarbonImmutable::now()->addDays(5)->format('Y-m-d');

        $firstId = $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $date,
            'appointment_time' => '09:00',
            'duration_minutes' => 30,
            'reason' => 'First visit',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $date,
            'appointment_time' => '11:00',
            'duration_minutes' => 30,
            'reason' => 'Blocked slot',
        ])->assertCreated();

        // Rescheduling first appointment to 11:00 conflicts
        $this->patchJson("/api/appointments/{$firstId}/reschedule", [
            'appointment_date' => $date,
            'appointment_time' => '11:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);

        // Rescheduling to 13:00 succeeds and records history
        $this->patchJson("/api/appointments/{$firstId}/reschedule", [
            'appointment_date' => $date,
            'appointment_time' => '13:00',
            'notes' => 'Patient requested afternoon',
        ])->assertOk()
            ->assertJsonPath('data.appointment_time', '13:00')
            ->assertJsonPath('data.histories.0.action', 'rescheduled');
    }

    public function test_appointment_cancellation_frees_slot_and_status_updates_work(): void
    {
        $date = CarbonImmutable::now()->addDays(6)->format('Y-m-d');

        $appointmentId = $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $date,
            'appointment_time' => '10:00',
            'duration_minutes' => 30,
            'reason' => 'Lab review',
        ])->assertCreated()->json('data.id');

        // Update status to confirmed
        $this->patchJson("/api/appointments/{$appointmentId}/status", [
            'status' => 'confirmed',
        ])->assertOk()->assertJsonPath('data.status', 'confirmed');

        // Cancel appointment
        $this->postJson("/api/appointments/{$appointmentId}/cancel", [
            'cancellation_reason' => 'Patient unavailable',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Patient unavailable');

        // Cancelled appointment cannot be rescheduled
        $this->patchJson("/api/appointments/{$appointmentId}/reschedule", [
            'appointment_date' => $date,
            'appointment_time' => '14:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        // Cancelled slot is now free for a new booking
        $secondId = $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $date,
            'appointment_time' => '10:00',
            'duration_minutes' => 30,
            'reason' => 'Rebooked visit',
        ])->assertCreated()->json('data.id');

        // Mark second appointment as completed
        $this->patchJson("/api/appointments/{$secondId}/status", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        // History endpoint returns timeline and patient appointments
        $this->getJson("/api/appointments/{$appointmentId}/history")
            ->assertOk()
            ->assertJsonCount(3, 'histories')
            ->assertJsonCount(2, 'patient_appointments');
    }

    public function test_search_filtering_and_permissions_are_enforced(): void
    {
        $date = CarbonImmutable::now()->addDays(7)->format('Y-m-d');

        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $date,
            'appointment_time' => '09:00',
            'reason' => 'Cardiology follow-up',
            'status' => 'confirmed',
        ])->assertCreated();

        $this->getJson('/api/appointments?search=Cardiology&status=confirmed&doctor_id='.$this->doctor->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // User without appointments.manage cannot create appointments
        $readOnlyRole = Role::firstOrCreate(['slug' => 'viewer'], ['name' => 'Viewer']);
        $viewPermission = Permission::where('slug', 'appointments.view')->firstOrFail();
        $readOnlyRole->permissions()->sync([$viewPermission->id]);
        $readOnlyUser = User::factory()->create(['role_id' => $readOnlyRole->id]);
        Sanctum::actingAs($readOnlyUser);

        $this->getJson('/api/appointments')->assertOk();
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $date,
            'appointment_time' => '15:00',
            'reason' => 'Unauthorized attempt',
        ])->assertForbidden();
    }
}

