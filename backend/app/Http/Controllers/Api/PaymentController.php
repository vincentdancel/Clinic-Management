<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentIndexRequest;
use App\Http\Requests\PaymentStoreRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function index(PaymentIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = Payment::query()->with([
            'receiver',
            'invoice.patient',
        ]);

        if (! empty($filters['method']) && $filters['method'] !== 'all') {
            $query->where('method', $filters['method']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['invoice_id'])) {
            $query->where('invoice_id', $filters['invoice_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('paid_at', '>=', CarbonImmutable::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('paid_at', '<=', CarbonImmutable::parse($filters['date_to'])->endOfDay());
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->where('receipt_number', 'like', '%'.$search.'%')
                    ->orWhere('reference_number', 'like', '%'.$search.'%')
                    ->orWhereHas('invoice', function ($invoiceQuery) use ($search): void {
                        $invoiceQuery->where('invoice_number', 'like', '%'.$search.'%')
                            ->orWhereHas('patient', function ($patientQuery) use ($search): void {
                                $patientQuery->where('patient_number', 'like', '%'.$search.'%')
                                    ->orWhere('first_name', 'like', '%'.$search.'%')
                                    ->orWhere('last_name', 'like', '%'.$search.'%');
                            });
                    });
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        $paginator = $query->orderByDesc('paid_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => PaymentResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(PaymentStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $idempotencyKey = isset($validated['idempotency_key']) ? trim((string) $validated['idempotency_key']) : null;
        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        $result = DB::transaction(function () use ($validated, $idempotencyKey, $request): array {
            if ($idempotencyKey !== null) {
                $existingPayment = Payment::query()
                    ->with(['receiver', 'invoice.patient', 'invoice.items'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingPayment) {
                    return [
                        'duplicate' => true,
                        'payment' => $existingPayment,
                        'invoice' => $existingPayment->invoice,
                    ];
                }
            }

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($validated['invoice_id']);

            if ($invoice->status === Invoice::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['Cannot record a payment for a cancelled invoice.'],
                ]);
            }

            if ($invoice->status === Invoice::STATUS_PAID) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['This invoice is already fully paid.'],
                ]);
            }

            $paidAmount = round((float) Payment::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', Payment::STATUS_COMPLETED)
                ->sum('amount'), 2);

            $remainingBalance = round(max(0, (float) $invoice->total_amount - $paidAmount), 2);
            $amount = round((float) $validated['amount'], 2);

            if ($remainingBalance <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['This invoice has no remaining balance.'],
                ]);
            }

            if ($amount > $remainingBalance) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf('Payment amount (%.2f) exceeds the remaining balance (%.2f).', $amount, $remainingBalance)],
                ]);
            }

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'received_by' => $request->user()?->id,
                'receipt_number' => $this->generateReceiptNumber(),
                'idempotency_key' => $idempotencyKey,
                'amount' => $amount,
                'method' => $validated['method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'paid_at' => isset($validated['paid_at'])
                    ? CarbonImmutable::parse($validated['paid_at'])
                    : CarbonImmutable::now(),
                'status' => Payment::STATUS_COMPLETED,
                'notes' => $validated['notes'] ?? null,
            ]);

            $newPaidAmount = round($paidAmount + $amount, 2);
            $oldStatus = $invoice->status;
            $invoice->status = $newPaidAmount >= (float) $invoice->total_amount
                ? Invoice::STATUS_PAID
                : Invoice::STATUS_PARTIALLY_PAID;
            $invoice->save();

            $this->auditLogs->log('invoice.payment_recorded', $invoice, $request->user(), [
                'status' => $oldStatus,
                'paid_amount' => $paidAmount,
            ], [
                'status' => $invoice->status,
                'paid_amount' => $newPaidAmount,
                'payment_id' => $payment->id,
                'receipt_number' => $payment->receipt_number,
                'amount' => $amount,
                'method' => $payment->method,
            ], $request);

            return [
                'duplicate' => false,
                'payment' => $payment,
                'invoice' => $invoice,
            ];
        });

        if ($result['duplicate']) {
            return response()->json([
                'message' => 'Duplicate payment detected. This payment has already been processed.',
                'duplicate' => true,
                'data' => new PaymentResource($result['payment']),
                'invoice' => new InvoiceResource($result['invoice']),
            ], 409);
        }

        /** @var Payment $payment */
        $payment = $result['payment'];
        /** @var Invoice $invoice */
        $invoice = $result['invoice'];

        $payment->load(['receiver', 'invoice.patient', 'invoice.items']);
        $invoice->load(['patient', 'consultation.doctor', 'appointment', 'issuer', 'items.service', 'payments.receiver']);

        return response()->json([
            'message' => $invoice->status === Invoice::STATUS_PAID
                ? 'Payment recorded. Invoice is now fully paid.'
                : 'Partial payment recorded successfully.',
            'data' => new PaymentResource($payment),
            'invoice' => new InvoiceResource($invoice),
        ], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load([
            'receiver',
            'invoice.patient',
            'invoice.items.service',
        ]);

        return response()->json([
            'data' => new PaymentResource($payment),
        ]);
    }

    private function generateReceiptNumber(): string
    {
        $year = CarbonImmutable::now()->format('Y');
        $prefix = "RCP-{$year}-";

        $lastNumber = Payment::query()
            ->where('receipt_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('receipt_number')
            ->value('receipt_number');

        $nextSequence = 1;
        if (is_string($lastNumber) && preg_match('/(\d+)$/', $lastNumber, $matches)) {
            $nextSequence = ((int) $matches[1]) + 1;
        }

        return sprintf('%s%06d', $prefix, $nextSequence);
    }
}

