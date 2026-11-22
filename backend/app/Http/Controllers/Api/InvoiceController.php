<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceIndexRequest;
use App\Http\Requests\InvoiceStoreRequest;
use App\Http\Requests\InvoiceUpdateRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function index(InvoiceIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = Invoice::query()->with([
            'patient',
            'consultation.doctor',
            'appointment',
            'issuer',
            'items.service',
            'payments',
        ]);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('issued_at', '>=', CarbonImmutable::parse($filters['date_from'])->toDateString());
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('issued_at', '<=', CarbonImmutable::parse($filters['date_to'])->toDateString());
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->where('invoice_number', 'like', '%'.$search.'%')
                    ->orWhere('notes', 'like', '%'.$search.'%')
                    ->orWhereHas('patient', function ($patientQuery) use ($search): void {
                        $patientQuery->where('patient_number', 'like', '%'.$search.'%')
                            ->orWhere('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%');
                    });
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        $paginator = $query->orderByDesc('issued_at')->orderByDesc('id')->paginate($perPage);

        $totalBilled = (float) Invoice::query()
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->sum('total_amount');

        $totalCollected = (float) Payment::query()
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', Invoice::STATUS_CANCELLED))
            ->sum('amount');

        $summary = [
            'total_billed' => round($totalBilled, 2),
            'total_collected' => round($totalCollected, 2),
            'total_outstanding' => round(max(0, $totalBilled - $totalCollected), 2),
            'unpaid_count' => Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count(),
            'partially_paid_count' => Invoice::query()->where('status', Invoice::STATUS_PARTIALLY_PAID)->count(),
            'paid_count' => Invoice::query()->where('status', Invoice::STATUS_PAID)->count(),
        ];

        return response()->json([
            'data' => InvoiceResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => $summary,
        ]);
    }

    public function options(): JsonResponse
    {
        $patients = Patient::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(250)
            ->get(['id', 'patient_number', 'first_name', 'last_name', 'phone'])
            ->map(fn (Patient $patient): array => [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'full_name' => trim($patient->first_name.' '.$patient->last_name),
                'phone' => $patient->phone,
            ]);

        $services = Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'price'])
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'description' => $service->description,
                'price' => (float) $service->price,
            ]);

        $consultations = Consultation::query()
            ->with(['patient:id,patient_number,first_name,last_name', 'doctor:id,first_name,last_name,job_title'])
            ->whereDoesntHave('invoice')
            ->where('status', '!=', Consultation::STATUS_CANCELLED)
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(fn (Consultation $consultation): array => [
                'id' => $consultation->id,
                'consultation_number' => $consultation->consultation_number,
                'patient_id' => $consultation->patient_id,
                'appointment_id' => $consultation->appointment_id,
                'started_at' => $consultation->started_at?->toISOString(),
                'chief_complaint' => $consultation->chief_complaint,
                'patient_name' => $consultation->patient ? trim($consultation->patient->first_name.' '.$consultation->patient->last_name) : null,
                'doctor_name' => $consultation->doctor ? trim($consultation->doctor->first_name.' '.$consultation->doctor->last_name) : null,
            ]);

        $appointments = Appointment::query()
            ->with(['patient:id,first_name,last_name', 'doctor:id,first_name,last_name'])
            ->whereDoesntHave('invoice')
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->orderByDesc('scheduled_at')
            ->limit(100)
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'appointment_number' => $appointment->appointment_number,
                'patient_id' => $appointment->patient_id,
                'scheduled_at' => $appointment->scheduled_at?->toISOString(),
                'patient_name' => $appointment->patient ? trim($appointment->patient->first_name.' '.$appointment->patient->last_name) : null,
                'doctor_name' => $appointment->doctor ? trim($appointment->doctor->first_name.' '.$appointment->doctor->last_name) : null,
            ]);

        return response()->json([
            'patients' => $patients,
            'services' => $services,
            'consultations' => $consultations,
            'appointments' => $appointments,
        ]);
    }

    public function store(InvoiceStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $invoice = DB::transaction(function () use ($validated, $request): Invoice {
            $issuedAt = isset($validated['issued_at'])
                ? CarbonImmutable::parse($validated['issued_at'])->toDateString()
                : CarbonImmutable::today()->toDateString();

            $discount = round((float) ($validated['discount_amount'] ?? 0), 2);
            $tax = round((float) ($validated['tax_amount'] ?? 0), 2);

            $subtotal = 0.0;
            $preparedItems = [];

            foreach ($validated['items'] as $item) {
                $quantity = round((float) $item['quantity'], 3);
                $unitPrice = round((float) $item['unit_price'], 2);
                $lineTotal = round($quantity * $unitPrice, 2);
                $subtotal = round($subtotal + $lineTotal, 2);

                $preparedItems[] = [
                    'item_type' => $item['item_type'],
                    'service_id' => $item['service_id'] ?? null,
                    'prescription_item_id' => $item['prescription_item_id'] ?? null,
                    'description' => trim($item['description']),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            if ($discount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount amount cannot exceed the invoice subtotal.'],
                ]);
            }

            $totalAmount = round(max(0, $subtotal - $discount + $tax), 2);

            $invoice = Invoice::create([
                'patient_id' => $validated['patient_id'],
                'appointment_id' => $validated['appointment_id'] ?? null,
                'consultation_id' => $validated['consultation_id'] ?? null,
                'issued_by' => $request->user()?->id,
                'invoice_number' => $this->generateInvoiceNumber(),
                'issued_at' => $issuedAt,
                'due_at' => $validated['due_at'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total_amount' => $totalAmount,
                'status' => Invoice::STATUS_UNPAID,
                'notes' => $validated['notes'] ?? null,
            ]);

            $invoice->items()->createMany($preparedItems);

            $this->auditLogs->log('invoice.created', $invoice, $request->user(), null, [
                'invoice_number' => $invoice->invoice_number,
                'patient_id' => $invoice->patient_id,
                'total_amount' => (float) $invoice->total_amount,
            ], $request);

            return $invoice;
        });

        $invoice->load(['patient', 'consultation.doctor', 'appointment', 'issuer', 'items.service', 'payments.receiver']);

        return response()->json([
            'message' => 'Invoice generated successfully.',
            'data' => new InvoiceResource($invoice),
        ], 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load([
            'patient',
            'consultation.doctor',
            'appointment',
            'issuer',
            'items.service',
            'payments.receiver',
        ]);

        return response()->json([
            'data' => new InvoiceResource($invoice),
        ]);
    }

    public function update(InvoiceUpdateRequest $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validated();

        $updated = DB::transaction(function () use ($validated, $invoice, $request): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === Invoice::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'invoice' => ['Cancelled invoices cannot be modified.'],
                ]);
            }

            if ($locked->status === Invoice::STATUS_PAID) {
                throw ValidationException::withMessages([
                    'invoice' => ['Fully paid invoices cannot be modified.'],
                ]);
            }

            $oldValues = [
                'subtotal' => (float) $locked->subtotal,
                'discount_amount' => (float) $locked->discount_amount,
                'tax_amount' => (float) $locked->tax_amount,
                'total_amount' => (float) $locked->total_amount,
            ];

            if (isset($validated['items'])) {
                $locked->items()->delete();
                $preparedItems = [];
                foreach ($validated['items'] as $item) {
                    $quantity = round((float) $item['quantity'], 3);
                    $unitPrice = round((float) $item['unit_price'], 2);
                    $preparedItems[] = [
                        'item_type' => $item['item_type'],
                        'service_id' => $item['service_id'] ?? null,
                        'prescription_item_id' => $item['prescription_item_id'] ?? null,
                        'description' => trim($item['description']),
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => round($quantity * $unitPrice, 2),
                    ];
                }
                $locked->items()->createMany($preparedItems);
            }

            $locked->fill([
                'issued_at' => $validated['issued_at'] ?? $locked->issued_at,
                'due_at' => array_key_exists('due_at', $validated) ? $validated['due_at'] : $locked->due_at,
                'discount_amount' => $validated['discount_amount'] ?? $locked->discount_amount,
                'tax_amount' => $validated['tax_amount'] ?? $locked->tax_amount,
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $locked->notes,
            ]);

            $subtotal = round((float) $locked->items()->sum('line_total'), 2);
            $discount = round((float) $locked->discount_amount, 2);
            $tax = round((float) $locked->tax_amount, 2);

            if ($discount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount amount cannot exceed the invoice subtotal.'],
                ]);
            }

            $newTotal = round(max(0, $subtotal - $discount + $tax), 2);
            $paidAmount = $locked->paid_amount;

            if ($newTotal < $paidAmount) {
                throw ValidationException::withMessages([
                    'items' => [sprintf('New total (%.2f) cannot be less than already paid amount (%.2f).', $newTotal, $paidAmount)],
                ]);
            }

            $locked->recalculateTotals();

            $this->auditLogs->log('invoice.updated', $locked, $request->user(), $oldValues, [
                'subtotal' => (float) $locked->subtotal,
                'discount_amount' => (float) $locked->discount_amount,
                'tax_amount' => (float) $locked->tax_amount,
                'total_amount' => (float) $locked->total_amount,
            ], $request);

            return $locked;
        });

        $updated->load(['patient', 'consultation.doctor', 'appointment', 'issuer', 'items.service', 'payments.receiver']);

        return response()->json([
            'message' => 'Invoice updated successfully.',
            'data' => new InvoiceResource($updated),
        ]);
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $cancelled = DB::transaction(function () use ($invoice, $request): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === Invoice::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'invoice' => ['This invoice is already cancelled.'],
                ]);
            }

            if ($locked->paid_amount > 0) {
                throw ValidationException::withMessages([
                    'invoice' => ['Invoices with recorded payments cannot be cancelled.'],
                ]);
            }

            $oldStatus = $locked->status;
            $locked->status = Invoice::STATUS_CANCELLED;
            $locked->save();

            $this->auditLogs->log('invoice.cancelled', $locked, $request->user(), ['status' => $oldStatus], [
                'status' => Invoice::STATUS_CANCELLED,
            ], $request);

            return $locked;
        });

        $cancelled->load(['patient', 'consultation.doctor', 'appointment', 'issuer', 'items.service', 'payments.receiver']);

        return response()->json([
            'message' => 'Invoice cancelled.',
            'data' => new InvoiceResource($cancelled),
        ]);
    }

    private function generateInvoiceNumber(): string
    {
        $year = CarbonImmutable::now()->format('Y');
        $prefix = "INV-{$year}-";

        $lastNumber = Invoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $nextSequence = 1;
        if (is_string($lastNumber) && preg_match('/(\d+)$/', $lastNumber, $matches)) {
            $nextSequence = ((int) $matches[1]) + 1;
        }

        return sprintf('%s%06d', $prefix, $nextSequence);
    }
}

