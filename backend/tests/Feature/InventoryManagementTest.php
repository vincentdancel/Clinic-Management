<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\InventoryTransaction;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $inventoryUser;
    private User $doctorUser;
    private User $receptionistUser;
    private Employee $doctor;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $manageInventory = Permission::firstOrCreate(['slug' => 'inventory.manage'], ['name' => 'Manage inventory']);
        $viewClinical = Permission::firstOrCreate(['slug' => 'clinical.view'], ['name' => 'View clinical records']);
        $manageClinical = Permission::firstOrCreate(['slug' => 'clinical.manage'], ['name' => 'Manage clinical intake']);
        $diagnoseClinical = Permission::firstOrCreate(['slug' => 'clinical.diagnose'], ['name' => 'Diagnose and prescribe']);

        $inventoryRole = Role::firstOrCreate(['slug' => 'inventory-staff'], ['name' => 'Inventory Staff']);
        $inventoryRole->permissions()->syncWithoutDetaching([$manageInventory->id]);

        $doctorRole = Role::firstOrCreate(['slug' => 'doctor'], ['name' => 'Doctor']);
        $doctorRole->permissions()->syncWithoutDetaching([$viewClinical->id, $manageClinical->id, $diagnoseClinical->id]);

        $receptionistRole = Role::firstOrCreate(['slug' => 'receptionist'], ['name' => 'Receptionist']);

        $this->inventoryUser = User::factory()->create(['role_id' => $inventoryRole->id, 'name' => 'Inventory Staff']);
        $this->doctorUser = User::factory()->create(['role_id' => $doctorRole->id, 'name' => 'Dr. Mateo Reyes']);
        $this->receptionistUser = User::factory()->create(['role_id' => $receptionistRole->id, 'name' => 'Receptionist Mia']);

        $department = Department::create(['name' => 'General Medicine', 'code' => 'GENMED', 'is_active' => true]);
        $this->doctor = Employee::create([
            'user_id' => $this->doctorUser->id,
            'department_id' => $department->id,
            'employee_number' => 'EMP-2026-000050',
            'first_name' => 'Mateo',
            'last_name' => 'Reyes',
            'employee_category' => 'doctor',
            'job_title' => 'Physician',
            'hire_date' => '2024-01-01',
            'employment_status' => 'active',
        ]);

        $this->patient = Patient::create([
            'patient_number' => 'PAT-2026-000500',
            'first_name' => 'Liza',
            'last_name' => 'Santos',
            'gender' => 'female',
            'date_of_birth' => '1992-08-12',
            'phone' => '09175551234',
        ]);
    }

    public function test_inventory_crud_batch_receiving_and_stock_adjustments(): void
    {
        Sanctum::actingAs($this->inventoryUser);

        $catRes = $this->postJson('/api/inventory/categories', [
            'name' => 'Analgesics',
            'description' => 'Pain relievers and antipyretics',
        ])->assertCreated();

        $categoryId = $catRes->json('data.id');

        $medRes = $this->postJson('/api/inventory/medicines', [
            'medicine_category_id' => $categoryId,
            'name' => 'Paracetamol 500mg',
            'generic_name' => 'Acetaminophen',
            'dosage_form' => 'Tablet',
            'unit' => 'tablets',
            'reorder_level' => 20,
            'unit_cost' => 2.50,
            'selling_price' => 5.00,
            'requires_prescription' => false,
        ])->assertCreated();

        $medicineId = $medRes->json('data.id');
        $this->assertMatchesRegularExpression('/^MED-\d{4}-\d{5}$/', $medRes->json('data.sku'));

        // Receive two batches with different expiry dates
        $this->postJson("/api/inventory/medicines/{$medicineId}/receive", [
            'batch_number' => 'BATCH-EARLIER',
            'received_at' => CarbonImmutable::today()->subDays(5)->toDateString(),
            'expires_at' => CarbonImmutable::today()->addDays(20)->toDateString(),
            'quantity_received' => 30,
            'unit_cost' => 2.50,
        ])->assertCreated();

        $this->postJson("/api/inventory/medicines/{$medicineId}/receive", [
            'batch_number' => 'BATCH-LATER',
            'received_at' => CarbonImmutable::today()->toDateString(),
            'expires_at' => CarbonImmutable::today()->addDays(180)->toDateString(),
            'quantity_received' => 50,
            'unit_cost' => 2.60,
        ])->assertCreated();

        $this->getJson("/api/inventory/medicines/{$medicineId}")
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 80)
            ->assertJsonCount(2, 'data.batches');

        // Adjust out 10 tablets -> should deduct from earliest-expiring batch (FEFO)
        $this->postJson("/api/inventory/medicines/{$medicineId}/adjust", [
            'type' => 'damaged',
            'quantity' => 10,
            'notes' => 'Damaged blister pack',
        ])->assertCreated();

        $earlierBatch = MedicineBatch::query()->where('batch_number', 'BATCH-EARLIER')->firstOrFail();
        $laterBatch = MedicineBatch::query()->where('batch_number', 'BATCH-LATER')->firstOrFail();

        $this->assertEquals(20.0, (float) $earlierBatch->quantity_remaining);
        $this->assertEquals(50.0, (float) $laterBatch->quantity_remaining);

        // Over-deduction should fail with 422
        $this->postJson("/api/inventory/medicines/{$medicineId}/adjust", [
            'type' => 'adjustment_out',
            'quantity' => 200,
        ])->assertUnprocessable();

        $this->getJson('/api/inventory/transactions')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_dispensing_prescription_deducts_inventory_fefo_and_prevents_insufficient_or_double_deduction(): void
    {
        $category = MedicineCategory::create(['name' => 'Antibiotics']);
        $medicine = Medicine::create([
            'medicine_category_id' => $category->id,
            'sku' => 'MED-TEST-001',
            'name' => 'Amoxicillin 500mg',
            'unit' => 'capsules',
            'reorder_level' => 10,
            'unit_cost' => 8.00,
            'selling_price' => 15.00,
            'requires_prescription' => true,
            'is_active' => true,
        ]);

        // Expired batch should NOT be used for dispensing
        MedicineBatch::create([
            'medicine_id' => $medicine->id,
            'batch_number' => 'BATCH-EXPIRED',
            'received_at' => CarbonImmutable::today()->subMonths(6)->toDateString(),
            'expires_at' => CarbonImmutable::today()->subDay()->toDateString(),
            'quantity_received' => 100,
            'quantity_remaining' => 100,
            'unit_cost' => 8.00,
        ]);

        // Valid batch with 15 capsules
        $validBatch = MedicineBatch::create([
            'medicine_id' => $medicine->id,
            'batch_number' => 'BATCH-VALID',
            'received_at' => CarbonImmutable::today()->toDateString(),
            'expires_at' => CarbonImmutable::today()->addMonths(6)->toDateString(),
            'quantity_received' => 15,
            'quantity_remaining' => 15,
            'unit_cost' => 8.00,
        ]);

        Sanctum::actingAs($this->doctorUser);

        $consultationId = $this->postJson('/api/consultations', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'chief_complaint' => 'Bacterial infection',
            'diagnosis' => 'Acute pharyngitis',
        ])->assertCreated()->json('data.id');

        // Prescription requesting 20 capsules (more than valid 15)
        $rxOver = $this->postJson("/api/consultations/{$consultationId}/prescriptions", [
            'items' => [[
                'medicine_id' => $medicine->id,
                'medicine_name' => $medicine->name,
                'dosage' => '500 mg',
                'frequency' => 'TID',
                'quantity' => 20,
                'quantity_unit' => 'capsules',
            ]],
        ])->assertCreated()->json('data.id');

        // Dispensing should fail because expired batch is excluded and valid stock is only 15
        $this->patchJson("/api/consultations/{$consultationId}/prescriptions/{$rxOver}/status", [
            'status' => 'dispensed',
        ])->assertUnprocessable();

        $this->assertEquals(15.0, (float) $validBatch->fresh()->quantity_remaining);

        // Prescription requesting 10 capsules (within valid 15)
        $rxValid = $this->postJson("/api/consultations/{$consultationId}/prescriptions", [
            'items' => [[
                'medicine_id' => $medicine->id,
                'medicine_name' => $medicine->name,
                'dosage' => '500 mg',
                'frequency' => 'TID',
                'quantity' => 10,
                'quantity_unit' => 'capsules',
            ]],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/consultations/{$consultationId}/prescriptions/{$rxValid}/status", [
            'status' => 'dispensed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'dispensed');

        $this->assertEquals(5.0, (float) $validBatch->fresh()->quantity_remaining);
        $this->assertSame(1, InventoryTransaction::query()->where('type', 'dispense')->count());

        // Reverting dispensed prescription must be blocked
        $this->patchJson("/api/consultations/{$consultationId}/prescriptions/{$rxValid}/status", [
            'status' => 'active',
        ])->assertUnprocessable();
    }

    public function test_unauthorized_user_cannot_access_inventory_endpoints(): void
    {
        Sanctum::actingAs($this->receptionistUser);

        $this->getJson('/api/inventory/options')->assertForbidden();
        $this->getJson('/api/inventory/medicines')->assertForbidden();
        $this->postJson('/api/inventory/categories', ['name' => 'Test'])->assertForbidden();
    }
}

