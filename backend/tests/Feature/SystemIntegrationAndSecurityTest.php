<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemIntegrationAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_access_is_rejected_across_protected_modules(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/patients')->assertUnauthorized();
        $this->getJson('/api/employees')->assertUnauthorized();
        $this->getJson('/api/appointments')->assertUnauthorized();
        $this->getJson('/api/consultations')->assertUnauthorized();
        $this->getJson('/api/invoices')->assertUnauthorized();
        $this->getJson('/api/payments')->assertUnauthorized();
        $this->getJson('/api/inventory/medicines')->assertUnauthorized();
        $this->getJson('/api/reports/options')->assertUnauthorized();
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    public function test_password_hashing_and_end_to_end_business_logic_dashboard_consistency(): void
    {
        $allPerms = [
            'patients.view', 'patients.manage',
            'employees.view', 'employees.manage',
            'appointments.view', 'appointments.manage',
            'clinical.view', 'clinical.manage', 'clinical.diagnose',
            'billing.view', 'billing.manage',
            'inventory.manage',
            'reports.view', 'reports.export',
        ];

        $adminRole = Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $permIds = collect($allPerms)->map(fn (string $slug): int => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id);
        $adminRole->permissions()->syncWithoutDetaching($permIds->all());

        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'password' => 'StrongPass#2026!',
        ]);

        // Verify password is hashed in DB
        $this->assertNotSame('StrongPass#2026!', $admin->password);
        $this->assertTrue(Hash::check('StrongPass#2026!', $admin->password));

        Sanctum::actingAs($admin);

        // Create patient
        $patientId = $this->postJson('/api/patients', [
            'first_name' => 'Carlo',
            'last_name' => 'Mendoza',
            'gender' => 'male',
            'date_of_birth' => '1988-03-20',
            'phone' => '09181112222',
        ])->assertCreated()->json('data.id');

        // Create doctor
        $dept = Department::create(['name' => 'Cardiology', 'code' => 'CARD', 'is_active' => true]);
        $doctor = Employee::create([
            'department_id' => $dept->id,
            'employee_number' => 'EMP-2026-000099',
            'first_name' => 'Sofia',
            'last_name' => 'Villanueva',
            'employee_category' => 'doctor',
            'job_title' => 'Cardiologist',
            'hire_date' => '2024-01-15',
            'employment_status' => 'active',
        ]);

        // Book appointment & verify double-booking prevention
        $slot = CarbonImmutable::now()->addDays(2)->setTime(10, 0);
        $this->postJson('/api/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $slot->toIso8601String(),
            'duration_minutes' => 30,
            'reason' => 'Cardiac evaluation',
        ])->assertCreated();

        $this->postJson('/api/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $slot->addMinutes(15)->toIso8601String(),
            'duration_minutes' => 30,
            'reason' => 'Overlapping slot',
        ])->assertUnprocessable();

        // Generate invoice (subtotal: 1000, discount: 100, tax: 50 => total: 950)
        $service = Service::create(['code' => 'SRV-CARD-01', 'name' => 'ECG', 'price' => 1000.00, 'is_active' => true]);
        $invoiceId = $this->postJson('/api/invoices', [
            'patient_id' => $patientId,
            'discount_amount' => 100,
            'tax_amount' => 50,
            'items' => [[
                'item_type' => 'service',
                'service_id' => $service->id,
                'description' => 'ECG',
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ])->assertCreated()->json('data.id');

        // Partial payment (400) -> status partially_paid, remaining 550
        $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 400,
            'method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('invoice.status', Invoice::STATUS_PARTIALLY_PAID)
            ->assertJsonPath('invoice.remaining_balance', 550);

        // Overpayment (600 > 550) -> rejected
        $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 600,
            'method' => 'cash',
        ])->assertUnprocessable();

        // Dashboard reflects exact stats
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.total_patients', 1)
            ->assertJsonPath('stats.total_appointments', 1)
            ->assertJsonPath('stats.today_revenue', 400)
            ->assertJsonPath('stats.pending_payments', 550);
    }
}

