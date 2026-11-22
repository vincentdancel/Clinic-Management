<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $cashierUser;
    private User $receptionistUser;
    private User $nurseUser;
    private Patient $patient;
    private Service $cbcService;
    private Service $ecgService;

    protected function setUp(): void
    {
        parent::setUp();

        $viewBilling = Permission::firstOrCreate(['slug' => 'billing.view'], ['name' => 'View billing and payment history']);
        $manageBilling = Permission::firstOrCreate(['slug' => 'billing.manage'], ['name' => 'Manage billing and payments']);

        $cashierRole = Role::firstOrCreate(['slug' => 'cashier'], ['name' => 'Cashier']);
        $cashierRole->permissions()->syncWithoutDetaching([$viewBilling->id, $manageBilling->id]);

        $receptionistRole = Role::firstOrCreate(['slug' => 'receptionist'], ['name' => 'Receptionist']);
        $receptionistRole->permissions()->syncWithoutDetaching([$viewBilling->id]);

        $nurseRole = Role::firstOrCreate(['slug' => 'nurse'], ['name' => 'Nurse']);

        $this->cashierUser = User::factory()->create(['role_id' => $cashierRole->id, 'name' => 'Cashier Clara']);
        $this->receptionistUser = User::factory()->create(['role_id' => $receptionistRole->id, 'name' => 'Receptionist Mia']);
        $this->nurseUser = User::factory()->create(['role_id' => $nurseRole->id, 'name' => 'Nurse Nina']);

        $this->patient = Patient::create([
            'patient_number' => 'PT-2026-000101',
            'first_name' => 'Carlos',
            'last_name' => 'Mendoza',
            'gender' => 'male',
            'date_of_birth' => '1988-04-12',
            'phone' => '09171234567',
        ]);

        $this->cbcService = Service::create([
            'code' => 'SRV-CBC',
            'name' => 'Complete Blood Count',
            'price' => 450.00,
            'is_active' => true,
        ]);

        $this->ecgService = Service::create([
            'code' => 'SRV-ECG',
            'name' => 'Electrocardiogram (ECG)',
            'price' => 650.00,
            'is_active' => true,
        ]);
    }

    public function test_can_generate_invoice_with_consultation_fee_and_service_charges(): void
    {
        Sanctum::actingAs($this->cashierUser);

        $response = $this->postJson('/api/invoices', [
            'patient_id' => $this->patient->id,
            'issued_at' => '2026-11-22',
            'due_at' => '2026-11-29',
            'discount_amount' => 100.00,
            'tax_amount' => 50.00,
            'notes' => 'Outpatient consultation and labs',
            'items' => [
                [
                    'item_type' => 'consultation',
                    'description' => 'General Physician Consultation Fee',
                    'quantity' => 1,
                    'unit_price' => 800.00,
                ],
                [
                    'item_type' => 'service',
                    'service_id' => $this->cbcService->id,
                    'description' => $this->cbcService->name,
                    'quantity' => 1,
                    'unit_price' => 450.00,
                ],
                [
                    'item_type' => 'service',
                    'service_id' => $this->ecgService->id,
                    'description' => $this->ecgService->name,
                    'quantity' => 2,
                    'unit_price' => 650.00,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.subtotal', 2550)
            ->assertJsonPath('data.discount_amount', 100)
            ->assertJsonPath('data.tax_amount', 50)
            ->assertJsonPath('data.total_amount', 2500)
            ->assertJsonPath('data.paid_amount', 0)
            ->assertJsonPath('data.remaining_balance', 2500);

        $invoiceNumber = $response->json('data.invoice_number');
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $invoiceNumber);
        $this->assertCount(3, $response->json('data.items'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.created', 'auditable_type' => Invoice::class]);
    }

    public function test_full_payment_marks_invoice_paid_and_generates_receipt(): void
    {
        Sanctum::actingAs($this->cashierUser);

        $invoiceResponse = $this->postJson('/api/invoices', [
            'patient_id' => $this->patient->id,
            'items' => [
                [
                    'item_type' => 'consultation',
                    'description' => 'Consultation Fee',
                    'quantity' => 1,
                    'unit_price' => 1200.00,
                ],
            ],
        ])->assertCreated();

        $invoiceId = $invoiceResponse->json('data.id');

        $paymentResponse = $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 1200.00,
            'method' => 'cash',
            'idempotency_key' => 'pay-full-001',
            'notes' => 'Paid in full at counter',
        ]);

        $paymentResponse->assertCreated()
            ->assertJsonPath('data.amount', 1200)
            ->assertJsonPath('data.method', 'cash')
            ->assertJsonPath('invoice.status', 'paid')
            ->assertJsonPath('invoice.paid_amount', 1200)
            ->assertJsonPath('invoice.remaining_balance', 0);

        $receiptNumber = $paymentResponse->json('data.receipt_number');
        $this->assertMatchesRegularExpression('/^RCP-\d{4}-\d{6}$/', $receiptNumber);
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => 'paid']);
    }

    public function test_partial_payments_update_remaining_balance_and_transition_to_paid(): void
    {
        Sanctum::actingAs($this->cashierUser);

        $invoiceResponse = $this->postJson('/api/invoices', [
            'patient_id' => $this->patient->id,
            'items' => [
                [
                    'item_type' => 'consultation',
                    'description' => 'Specialist Consultation',
                    'quantity' => 1,
                    'unit_price' => 1500.00,
                ],
                [
                    'item_type' => 'service',
                    'service_id' => $this->cbcService->id,
                    'description' => 'Complete Blood Count',
                    'quantity' => 1,
                    'unit_price' => 500.00,
                ],
            ],
        ])->assertCreated();

        $invoiceId = $invoiceResponse->json('data.id');

        // First partial payment (Card)
        $firstPayment = $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 800.00,
            'method' => 'card',
            'reference_number' => 'CARD-TXN-1001',
            'idempotency_key' => 'partial-1',
        ]);

        $firstPayment->assertCreated()
            ->assertJsonPath('invoice.status', 'partially_paid')
            ->assertJsonPath('invoice.paid_amount', 800)
            ->assertJsonPath('invoice.remaining_balance', 1200);

        // Second partial payment (Bank Transfer)
        $secondPayment = $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 500.00,
            'method' => 'bank_transfer',
            'reference_number' => 'BANK-TXN-2002',
            'idempotency_key' => 'partial-2',
        ]);

        $secondPayment->assertCreated()
            ->assertJsonPath('invoice.status', 'partially_paid')
            ->assertJsonPath('invoice.paid_amount', 1300)
            ->assertJsonPath('invoice.remaining_balance', 700);

        // Final payment (Other)
        $finalPayment = $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 700.00,
            'method' => 'other',
            'reference_number' => 'HMO-REF-3003',
            'idempotency_key' => 'partial-3',
        ]);

        $finalPayment->assertCreated()
            ->assertJsonPath('invoice.status', 'paid')
            ->assertJsonPath('invoice.paid_amount', 2000)
            ->assertJsonPath('invoice.remaining_balance', 0);

        $this->assertEquals(3, Payment::query()->where('invoice_id', $invoiceId)->count());
    }

    public function test_prevents_duplicate_payment_processing_and_overpayment(): void
    {
        Sanctum::actingAs($this->cashierUser);

        $invoiceId = $this->postJson('/api/invoices', [
            'patient_id' => $this->patient->id,
            'items' => [
                [
                    'item_type' => 'consultation',
                    'description' => 'Consultation Fee',
                    'quantity' => 1,
                    'unit_price' => 1000.00,
                ],
            ],
        ])->assertCreated()->json('data.id');

        // First submission succeeds
        $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 400.00,
            'method' => 'cash',
            'idempotency_key' => 'dup-key-123',
        ])->assertCreated();

        // Duplicate submission with same idempotency_key is blocked with 409
        $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 400.00,
            'method' => 'cash',
            'idempotency_key' => 'dup-key-123',
        ])->assertStatus(409)
            ->assertJsonPath('duplicate', true);

        // Only one payment record exists and remaining balance is still 600
        $this->assertEquals(1, Payment::query()->where('invoice_id', $invoiceId)->count());
        $this->getJson("/api/invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 400)
            ->assertJsonPath('data.remaining_balance', 600);

        // Overpayment beyond remaining balance (600) is rejected with 422
        $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 700.00,
            'method' => 'cash',
            'idempotency_key' => 'overpay-key',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_cannot_cancel_invoice_with_payments_and_cannot_pay_cancelled_invoice(): void
    {
        Sanctum::actingAs($this->cashierUser);

        $paidInvoiceId = $this->postJson('/api/invoices', [
            'patient_id' => $this->patient->id,
            'items' => [['item_type' => 'consultation', 'description' => 'Consult', 'quantity' => 1, 'unit_price' => 500]],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/payments', [
            'invoice_id' => $paidInvoiceId,
            'amount' => 250,
            'method' => 'cash',
            'idempotency_key' => 'p-1',
        ])->assertCreated();

        $this->postJson("/api/invoices/{$paidInvoiceId}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);

        $unpaidInvoiceId = $this->postJson('/api/invoices', [
            'patient_id' => $this->patient->id,
            'items' => [['item_type' => 'consultation', 'description' => 'Consult 2', 'quantity' => 1, 'unit_price' => 500]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/invoices/{$unpaidInvoiceId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson('/api/payments', [
            'invoice_id' => $unpaidInvoiceId,
            'amount' => 500,
            'method' => 'cash',
            'idempotency_key' => 'p-2',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_id']);
    }

    public function test_search_filtering_and_permissions_work_for_invoices_and_transactions(): void
    {
        Sanctum::actingAs($this->cashierUser);

        $invoiceId = $this->postJson('/api/invoices', [
            'patient_id' => $this->patient->id,
            'items' => [['item_type' => 'service', 'service_id' => $this->cbcService->id, 'description' => 'CBC', 'quantity' => 1, 'unit_price' => 450]],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/payments', [
            'invoice_id' => $invoiceId,
            'amount' => 450,
            'method' => 'bank_transfer',
            'reference_number' => 'BPI-9988',
            'idempotency_key' => 'tx-1',
        ])->assertCreated();

        $this->getJson('/api/invoices?status=paid&search=Mendoza')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/payments?method=bank_transfer&search=BPI-9988')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Receptionist has billing.view (can view) but not billing.manage (cannot create)
        Sanctum::actingAs($this->receptionistUser);
        $this->getJson('/api/invoices')->assertOk();
        $this->postJson('/api/invoices', [
            'patient_id' => $this->patient->id,
            'items' => [['item_type' => 'consultation', 'description' => 'Test', 'quantity' => 1, 'unit_price' => 100]],
        ])->assertForbidden();

        // Nurse has no billing permissions
        Sanctum::actingAs($this->nurseUser);
        $this->getJson('/api/invoices')->assertForbidden();
        $this->getJson('/api/payments')->assertForbidden();
    }
}

