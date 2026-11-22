<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function receiveStock(Medicine $medicine, array $data, ?User $user = null, ?Request $request = null): MedicineBatch
    {
        return DB::transaction(function () use ($medicine, $data, $user, $request): MedicineBatch {
            $quantity = round((float) $data['quantity_received'], 3);
            $unitCost = isset($data['unit_cost']) ? round((float) $data['unit_cost'], 2) : (float) $medicine->unit_cost;

            $batch = $medicine->batches()->create([
                'batch_number' => trim((string) $data['batch_number']),
                'received_at' => CarbonImmutable::parse($data['received_at'] ?? now())->toDateString(),
                'expires_at' => ! empty($data['expires_at']) ? CarbonImmutable::parse($data['expires_at'])->toDateString() : null,
                'quantity_received' => $quantity,
                'quantity_remaining' => $quantity,
                'unit_cost' => $unitCost,
            ]);

            InventoryTransaction::create([
                'medicine_id' => $medicine->id,
                'medicine_batch_id' => $batch->id,
                'performed_by' => $user?->id,
                'type' => 'stock_in',
                'quantity_change' => $quantity,
                'unit_cost' => $unitCost,
                'reference_number' => $batch->batch_number,
                'notes' => $data['notes'] ?? 'Stock replenishment received.',
                'occurred_at' => now(),
            ]);

            $this->auditLogs->log('inventory.stock_received', $medicine, $user, null, [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'quantity_received' => $quantity,
                'expires_at' => $batch->expires_at?->toDateString(),
            ], $request);

            return $batch;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function adjustStock(Medicine $medicine, array $data, ?User $user = null, ?Request $request = null): InventoryTransaction
    {
        return DB::transaction(function () use ($medicine, $data, $user, $request): InventoryTransaction {
            $type = (string) $data['type'];
            $quantity = round((float) $data['quantity'], 3);
            $isAddition = in_array($type, ['adjustment_in', 'return'], true);
            $today = CarbonImmutable::today()->toDateString();

            if ($isAddition) {
                $batch = null;
                if (! empty($data['medicine_batch_id'])) {
                    $batch = MedicineBatch::query()
                        ->where('medicine_id', $medicine->id)
                        ->lockForUpdate()
                        ->findOrFail($data['medicine_batch_id']);
                    $batch->increment('quantity_remaining', $quantity);
                } else {
                    $batch = $medicine->batches()->create([
                        'batch_number' => 'ADJ-'.now()->format('YmdHis'),
                        'received_at' => $today,
                        'expires_at' => null,
                        'quantity_received' => $quantity,
                        'quantity_remaining' => $quantity,
                        'unit_cost' => (float) $medicine->unit_cost,
                    ]);
                }

                $transaction = InventoryTransaction::create([
                    'medicine_id' => $medicine->id,
                    'medicine_batch_id' => $batch->id,
                    'performed_by' => $user?->id,
                    'type' => $type,
                    'quantity_change' => $quantity,
                    'unit_cost' => (float) $batch->unit_cost,
                    'reference_number' => $data['reference_number'] ?? $batch->batch_number,
                    'notes' => $data['notes'] ?? null,
                    'occurred_at' => now(),
                ]);

                $this->auditLogs->log('inventory.stock_adjusted', $medicine, $user, null, [
                    'type' => $type,
                    'quantity_change' => $quantity,
                    'batch_id' => $batch->id,
                ], $request);

                return $transaction;
            }

            // Deduction types: adjustment_out, expired, damaged
            if (! empty($data['medicine_batch_id'])) {
                $batch = MedicineBatch::query()
                    ->where('medicine_id', $medicine->id)
                    ->lockForUpdate()
                    ->findOrFail($data['medicine_batch_id']);

                if ((float) $batch->quantity_remaining < $quantity) {
                    throw ValidationException::withMessages([
                        'quantity' => [sprintf('Insufficient stock in batch %s (available: %.3f).', $batch->batch_number, (float) $batch->quantity_remaining)],
                    ]);
                }

                $batch->update([
                    'quantity_remaining' => round((float) $batch->quantity_remaining - $quantity, 3),
                ]);

                $transaction = InventoryTransaction::create([
                    'medicine_id' => $medicine->id,
                    'medicine_batch_id' => $batch->id,
                    'performed_by' => $user?->id,
                    'type' => $type,
                    'quantity_change' => -$quantity,
                    'unit_cost' => (float) $batch->unit_cost,
                    'reference_number' => $data['reference_number'] ?? $batch->batch_number,
                    'notes' => $data['notes'] ?? null,
                    'occurred_at' => now(),
                ]);

                $this->auditLogs->log('inventory.stock_adjusted', $medicine, $user, null, [
                    'type' => $type,
                    'quantity_change' => -$quantity,
                    'batch_id' => $batch->id,
                ], $request);

                return $transaction;
            }

            $batchQuery = MedicineBatch::query()
                ->where('medicine_id', $medicine->id)
                ->where('quantity_remaining', '>', 0)
                ->lockForUpdate();

            if ($type !== 'expired') {
                $batchQuery->where(function ($query) use ($today): void {
                    $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today);
                });
            }

            $batches = $batchQuery
                ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expires_at')
                ->orderBy('received_at')
                ->orderBy('id')
                ->get();

            $available = round((float) $batches->sum('quantity_remaining'), 3);
            if ($available < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => [sprintf('Insufficient stock for %s (requested: %.3f, available: %.3f).', $medicine->name, $quantity, $available)],
                ]);
            }

            $remainingToDeduct = $quantity;
            $lastTransaction = null;

            foreach ($batches as $batch) {
                if ($remainingToDeduct <= 0) {
                    break;
                }

                $batchQty = (float) $batch->quantity_remaining;
                $deduct = round(min($batchQty, $remainingToDeduct), 3);
                $batch->update(['quantity_remaining' => round($batchQty - $deduct, 3)]);

                $lastTransaction = InventoryTransaction::create([
                    'medicine_id' => $medicine->id,
                    'medicine_batch_id' => $batch->id,
                    'performed_by' => $user?->id,
                    'type' => $type,
                    'quantity_change' => -$deduct,
                    'unit_cost' => (float) $batch->unit_cost,
                    'reference_number' => $data['reference_number'] ?? $batch->batch_number,
                    'notes' => $data['notes'] ?? null,
                    'occurred_at' => now(),
                ]);

                $remainingToDeduct = round($remainingToDeduct - $deduct, 3);
            }

            $this->auditLogs->log('inventory.stock_adjusted', $medicine, $user, null, [
                'type' => $type,
                'quantity_change' => -$quantity,
            ], $request);

            return $lastTransaction;
        });
    }

    /**
     * Deduct inventory using FEFO (First Expired, First Out) when dispensing a prescription.
     */
    public function deductForPrescription(Prescription $prescription, ?User $user = null, ?Request $request = null): void
    {
        $prescription->loadMissing(['items.medicine', 'consultation']);
        $today = CarbonImmutable::today()->toDateString();
        $reference = $prescription->consultation?->consultation_number ?? 'RX-'.$prescription->id;

        foreach ($prescription->items as $item) {
            if (! $item->medicine_id) {
                continue;
            }

            $quantityNeeded = round((float) $item->quantity, 3);
            if ($quantityNeeded <= 0) {
                continue;
            }

            $batches = MedicineBatch::query()
                ->where('medicine_id', $item->medicine_id)
                ->where('quantity_remaining', '>', 0)
                ->where(function ($query) use ($today): void {
                    $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today);
                })
                ->lockForUpdate()
                ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expires_at')
                ->orderBy('received_at')
                ->orderBy('id')
                ->get();

            $available = round((float) $batches->sum('quantity_remaining'), 3);
            $medicineName = $item->medicine?->name ?? $item->medicine_name ?? 'Medicine #'.$item->medicine_id;

            if ($available < $quantityNeeded) {
                throw ValidationException::withMessages([
                    'status' => [sprintf('Insufficient stock for %s (required: %.3f, available non-expired stock: %.3f).', $medicineName, $quantityNeeded, $available)],
                ]);
            }

            $remaining = $quantityNeeded;
            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $batchQty = (float) $batch->quantity_remaining;
                $deduct = round(min($batchQty, $remaining), 3);
                $batch->update(['quantity_remaining' => round($batchQty - $deduct, 3)]);

                InventoryTransaction::create([
                    'medicine_id' => $item->medicine_id,
                    'medicine_batch_id' => $batch->id,
                    'prescription_item_id' => $item->id,
                    'performed_by' => $user?->id,
                    'type' => 'dispense',
                    'quantity_change' => -$deduct,
                    'unit_cost' => (float) $batch->unit_cost,
                    'reference_number' => $reference,
                    'notes' => 'Dispensed via prescription #'.$prescription->id,
                    'occurred_at' => now(),
                ]);

                $remaining = round($remaining - $deduct, 3);
            }
        }
    }
}

