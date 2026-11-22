<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Department;
use App\Models\DoctorSpecialization;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $cashierUser;
    private User $doctorUser;
    private User $restrictedUser;
    private Employee $doctorEmployee;
    private Department $department;
    private Patient $patientA;
    private Patient $patientB;
    private MedicineCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $permSlugs = [
            'patients.view',
            'employees.view',
            'appointments.view',
            'clinical.view',
            'billing.view',
            'inventory.manage',
            'reports.view',
            'reports.export',
        ];
        $perms = [];
        foreach ($permSlugs as $slug) {
            $perms[$slug] = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }

        $adminRole = Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $adminRole->permissions()->sync(collect($perms)->pluck('id')->all());

        $cashierRole = Role::firstOrCreate(['slug' => 'cashier'], ['name' => 'Cashier']);
        $cashierRole->permissions()->sync([$perms['billing.view']->id, $perms['reports.view']->id, $perms['reports.export']->id]);

        $doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
        $doctorRole->permissions()->sync([$perms['patients.view']->id, $perms['appointments.view']->id, $perms['clinical.view']->id, $perms['reports.view']->id]);

        $guestStaffRole = Role::firstOrCreate(['slug' => 'guest-staff'], ['name' => 'Guest Staff']);

        $this->adminUser = User::factory()->create(['role_id' => $adminRole->id, 'name' => 'Admin User']);
        $this->cashierUser = User::factory()->create(['role_id' => $cashierRole->id, 'name' => 'Cashier User']);
        $this->doctorUser = User::factory()->create(['role_id' => $doctorRole->id, 'name' => 'Doctor User']);
        $this->restrictedUser = User::factory()->create(['role_id' => $guestStaffRole->id, 'name' => 'Restricted User']);

        $this->department = Department::create(['name' => 'Internal Medicine', 'code' => 'IM', 'is_active' => true]);
        $spec = DoctorSpecialization::create(['name' => 'General Practice', 'is_active' => true]);

        $this->doctorEmployee = Employee::create([
            'department_id' => $this->department->id,
            'employee_number' => 'EMP-001',
            'first_name' => 'Elena',
            'last_name' => 'Reyes',
            'employee_category' => 'doctor',
            'job_title' => 'Attending Physician',
            'hire_date' => '2025-01-15',
            'employment_status' => 'active',
        ]);
        $this->doctorEmployee->doctorSpecializations()->attach($spec->id, ['is_primary' => true]);

        Employee::create([
            'department_id' => $this->department->id,
            'employee_number' => 'EMP-002',
            'first_name' => 'Mark',
            'last_name' => 'Santos',
            'employee_category' => 'nurse',
            'job_title' => 'Clinic Nurse',
            'hire_date' => '2025-03-10',
            'employment_status' => 'on_leave',
        ]);

        $this->patientA = Patient::create([
            'patient_number' => 'PT-2026-0001',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'gender' => 'female',
            'date_of_birth' => '1992-05-10',
            'phone' => '09170001111',
            'email' => 'ana@example.com',
        ]);

        $this->patientB = Patient::create([
            'patient_number' => 'PT-2026-0002',
            'first_name' => 'Luis',
            'last_name' => 'Gomez',
            'gender' => 'male',
            'date_of_birth' => '1985-08-20',
            'phone' => '09170002222',
        ]);

        $today = CarbonImmutable::today();

        $appt1 = Appointment::create([
            'appointment_number' => 'APT-001',
            'patient_id' => $this->patientA->id,
            'doctor_id' => $this->doctorEmployee->id,
            'scheduled_at' => $today->setTime(9, 0),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_COMPLETED,
            'reason' => 'Annual checkup',
        ]);

        Appointment::create([
            'appointment_number' => 'APT-002',
            'patient_id' => $this->patientB->id,
            'doctor_id' => $this->doctorEmployee->id,
            'scheduled_at' => $today->setTime(10, 0),
            'duration_minutes' => 30,
            'status' => Appointment::STATUS_CANCELLED,
            'reason' => 'Rescheduled',
        ]);

        $consult1 = Consultation::create([
            'consultation_number' => 'CON-001',
            'patient_id' => $this->patientA->id,
            'doctor_id' => $this->doctorEmployee->id,
            'appointment_id' => $appt1->id,
            'started_at' => $today->setTime(9, 5),
            'ended_at' => $today->setTime(9, 25),
            'chief_complaint' => 'Mild fever',
            'diagnosis' => 'Viral URI',
            'status' => Consultation::STATUS_COMPLETED,
            'follow_up_date' => $today->addDays(7)->toDateString(),
        ]);

        Prescription::create([
            'consultation_id' => $consult1->id,
            'prescribed_by' => $this->doctorEmployee->id,
            'prescribed_at' => $today->toDateString(),
            'status' => 'active',
        ]);

        $paidInvoice = Invoice::create([
            'patient_id' => $this->patientA->id,
            'invoice_number' => 'INV-2026-0001',
            'issued_at' => $today->toDateString(),
            'due_at' => $today->addDays(7)->toDateString(),
            'subtotal' => 1500.00,
            'total_amount' => 1500.00,
            'status' => Invoice::STATUS_PAID,
        ]);

        Payment::create([
            'invoice_id' => $paidInvoice->id,
            'received_by' => $this->cashierUser->id,
            'receipt_number' => 'RCP-2026-0001',
            'amount' => 1500.00,
            'method' => Payment::METHOD_CASH,
            'paid_at' => $today->setTime(11, 0),
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $partialInvoice = Invoice::create([
            'patient_id' => $this->patientB->id,
            'invoice_number' => 'INV-2026-0002',
            'issued_at' => $today->subDays(10)->toDateString(),
            'due_at' => $today->subDays(3)->toDateString(),
            'subtotal' => 2000.00,
            'total_amount' => 2000.00,
            'status' => Invoice::STATUS_PARTIALLY_PAID,
        ]);

        Payment::create([
            'invoice_id' => $partialInvoice->id,
            'received_by' => $this->cashierUser->id,
            'receipt_number' => 'RCP-2026-0002',
            'amount' => 500.00,
            'method' => Payment::METHOD_CARD,
            'paid_at' => $today->subDays(1)->setTime(14, 0),
            'status' => Payment::STATUS_COMPLETED,
        ]);

        $this->category = MedicineCategory::create(['name' => 'Antibiotics']);

        $amox = Medicine::create([
            'medicine_category_id' => $this->category->id,
            'sku' => 'MED-AMOX-500',
            'name' => 'Amoxicillin 500mg',
            'unit' => 'capsule',
            'reorder_level' => 50,
            'unit_cost' => 5.00,
            'selling_price' => 12.00,
            'is_active' => true,
        ]);

        $para = Medicine::create([
            'medicine_category_id' => $this->category->id,
            'sku' => 'MED-PARA-500',
            'name' => 'Paracetamol 500mg',
            'unit' => 'tablet',
            'reorder_level' => 20,
            'unit_cost' => 2.00,
            'selling_price' => 5.00,
            'is_active' => true,
        ]);

        // Low stock valid batch for Amoxicillin (10 <= 50)
        MedicineBatch::create([
            'medicine_id' => $amox->id,
            'batch_number' => 'BATCH-AMOX-1',
            'received_at' => $today->subDays(30)->toDateString(),
            'expires_at' => $today->addMonths(6)->toDateString(),
            'quantity_received' => 100,
            'quantity_remaining' => 10,
            'unit_cost' => 5.00,
        ]);

        // Expired batch for Amoxicillin (5 units expired)
        MedicineBatch::create([
            'medicine_id' => $amox->id,
            'batch_number' => 'BATCH-AMOX-EXP',
            'received_at' => $today->subDays(120)->toDateString(),
            'expires_at' => $today->subDays(5)->toDateString(),
            'quantity_received' => 20,
            'quantity_remaining' => 5,
            'unit_cost' => 5.00,
        ]);

        // Healthy stock for Paracetamol (100 > 20)
        MedicineBatch::create([
            'medicine_id' => $para->id,
            'batch_number' => 'BATCH-PARA-1',
            'received_at' => $today->subDays(10)->toDateString(),
            'expires_at' => $today->addYear()->toDateString(),
            'quantity_received' => 100,
            'quantity_remaining' => 100,
            'unit_cost' => 2.00,
        ]);
    }

    public function test_all_twelve_reports_calculate_accurate_database_metrics(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->getJson('/api/reports/patient-registrations')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 2)
            ->assertJsonCount(2, 'data.rows');

        $this->getJson('/api/reports/appointments?period=daily')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 2)
            ->assertJsonPath('data.summary.1.value', 1)
            ->assertJsonPath('data.summary.2.value', 1);

        $this->getJson('/api/reports/consultations')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 1)
            ->assertJsonPath('data.summary.1.value', 1)
            ->assertJsonPath('data.summary.3.value', 1);

        $this->getJson('/api/reports/doctor-activity')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 1)
            ->assertJsonPath('data.rows.0.total_appointments', 2)
            ->assertJsonPath('data.rows.0.completed_consultations', 1)
            ->assertJsonPath('data.rows.0.prescriptions_issued', 1);

        $this->getJson('/api/reports/employees')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 2)
            ->assertJsonPath('data.summary.1.value', 1);

        $this->getJson('/api/reports/daily-revenue')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 2000)
            ->assertJsonPath('data.summary.1.value', 2);

        $this->getJson('/api/reports/monthly-revenue')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 2000)
            ->assertJsonPath('data.summary.1.value', 3500);

        $this->getJson('/api/reports/payments')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 2000)
            ->assertJsonPath('data.summary.2.value', 1500)
            ->assertJsonPath('data.summary.3.value', 500);

        $this->getJson('/api/reports/unpaid-invoices')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 1)
            ->assertJsonPath('data.summary.2.value', 1500)
            ->assertJsonPath('data.summary.3.value', 1);

        $this->getJson('/api/reports/medicine-inventory')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 2)
            ->assertJsonPath('data.summary.1.value', 110)
            ->assertJsonPath('data.summary.2.value', 250);

        $this->getJson('/api/reports/low-stock')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 1)
            ->assertJsonPath('data.rows.0.sku', 'MED-AMOX-500')
            ->assertJsonPath('data.rows.0.shortage', 40);

        $this->getJson('/api/reports/expired-medicines')
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 1)
            ->assertJsonPath('data.summary.1.value', 5)
            ->assertJsonPath('data.summary.2.value', 25);
    }

    public function test_date_range_and_search_filtering_work(): void
    {
        Sanctum::actingAs($this->adminUser);
        $today = CarbonImmutable::today()->toDateString();

        $this->getJson("/api/reports/payments?date_from={$today}&date_to={$today}")
            ->assertOk()
            ->assertJsonPath('data.summary.0.value', 1500)
            ->assertJsonCount(1, 'data.rows');

        $this->getJson('/api/reports/patient-registrations?search=Ana')
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.patient_number', 'PT-2026-0001');
    }

    public function test_dashboard_returns_all_analytics_metrics(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.total_patients', 2)
            ->assertJsonPath('stats.total_appointments', 2)
            ->assertJsonPath('stats.completed_consultations', 1)
            ->assertJsonPath('inventory_statistics.active_medicines', 2)
            ->assertJsonPath('inventory_statistics.total_stock_units', 110)
            ->assertJsonPath('inventory_statistics.low_stock_count', 1)
            ->assertJsonPath('inventory_statistics.expired_batches_count', 1)
            ->assertJsonCount(4, 'payment_status_distribution');
    }

    public function test_reports_enforce_user_permissions(): void
    {
        Sanctum::actingAs($this->restrictedUser);
        $this->getJson('/api/reports/options')->assertForbidden();
        $this->getJson('/api/reports/patient-registrations')->assertForbidden();

        Sanctum::actingAs($this->cashierUser);
        $options = $this->getJson('/api/reports/options')->assertOk();
        $allowedTypes = collect($options->json('data.catalog'))->pluck('type')->all();
        $this->assertContains('daily-revenue', $allowedTypes);
        $this->assertNotContains('consultations', $allowedTypes);
        $this->assertNotContains('medicine-inventory', $allowedTypes);

        $this->getJson('/api/reports/daily-revenue')->assertOk();
        $this->getJson('/api/reports/consultations')->assertForbidden();
        $this->getJson('/api/reports/medicine-inventory')->assertForbidden();

        Sanctum::actingAs($this->doctorUser);
        $this->getJson('/api/reports/consultations')->assertOk();
        $this->getJson('/api/reports/daily-revenue')->assertForbidden();
        $this->get('/api/reports/consultations/export?format=pdf')->assertForbidden();
    }

    public function test_pdf_and_excel_exports_generate_valid_files(): void
    {
        Sanctum::actingAs($this->adminUser);

        $pdfResponse = $this->get('/api/reports/daily-revenue/export?format=pdf');
        $pdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfResponse->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());

        $xlsxResponse = $this->get('/api/reports/medicine-inventory/export?format=xlsx');
        $xlsxResponse->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $xlsxResponse->headers->get('content-type')
        );

        $binary = $xlsxResponse->streamedContent();
        $this->assertStringStartsWith('PK', $binary);

        $tmpFile = tempnam(sys_get_temp_dir(), 'rep_xlsx_');
        file_put_contents($tmpFile, $binary);
        try {
            $spreadsheet = IOFactory::load($tmpFile);
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertStringContainsString('MEDICINE INVENTORY REPORT', (string) $sheet->getCell('A1')->getValue());
        } finally {
            @unlink($tmpFile);
        }
    }
}

