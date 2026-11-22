<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransaction;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function options(): JsonResponse
    {
        $today = CarbonImmutable::today();
        $soonCutoff = $today->addDays(30)->toDateString();

        $categories = MedicineCategory::query()
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        $activeMedicines = Medicine::query()
            ->where('is_active', true)
            ->withSum(['batches as stock_quantity' => fn ($query) => $query->where(function ($query) use ($today): void {
                $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today->toDateString());
            })], 'quantity_remaining')
            ->get(['id', 'name', 'reorder_level', 'unit_cost']);

        $lowStockCount = $activeMedicines->filter(fn (Medicine $m): bool => (float) ($m->stock_quantity ?? 0) <= (float) $m->reorder_level)->count();
        $outOfStockCount = $activeMedicines->filter(fn (Medicine $m): bool => (float) ($m->stock_quantity ?? 0) <= 0)->count();
        $totalValue = round((float) $activeMedicines->sum(fn (Medicine $m): float => (float) ($m->stock_quantity ?? 0) * (float) $m->unit_cost), 2);

        $expiringSoonCount = MedicineBatch::query()
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $today->toDateString())
            ->whereDate('expires_at', '<=', $soonCutoff)
            ->count();

        $expiredBatchesCount = MedicineBatch::query()
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $today->toDateString())
            ->count();

        return response()->json([
            'categories' => $categories,
            'dosage_forms' => ['Tablet', 'Capsule', 'Syrup', 'Suspension', 'Injection', 'Cream', 'Ointment', 'Drops', 'Inhaler', 'Solution'],
            'units' => ['tablets', 'capsules', 'bottles', 'vials', 'ampoules', 'tubes', 'boxes', 'sachets', 'units'],
            'summary' => [
                'total_medicines' => Medicine::query()->count(),
                'active_medicines' => $activeMedicines->count(),
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'expiring_soon_count' => $expiringSoonCount,
                'expired_batches_count' => $expiredBatchesCount,
                'total_inventory_value' => $totalValue,
            ],
        ]);
    }

    public function categoriesIndex(): JsonResponse
    {
        $categories = MedicineCategory::query()
            ->withCount('medicines')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('medicine_categories', 'name')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $category = MedicineCategory::create($validated);

        return response()->json([
            'message' => 'Medicine category created.',
            'data' => $category->loadCount('medicines'),
        ], 201);
    }

    public function updateCategory(Request $request, MedicineCategory $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('medicine_categories', 'name')->ignore($category->id)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Medicine category updated.',
            'data' => $category->loadCount('medicines'),
        ]);
    }

    public function destroyCategory(MedicineCategory $category): JsonResponse
    {
        if ($category->medicines()->exists()) {
            return response()->json(['message' => 'Cannot delete a category that still has medicines assigned.'], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Medicine category removed.']);
    }

    public function index(Request $request): JsonResponse
    {
        $today = CarbonImmutable::today()->toDateString();
        $soonCutoff = CarbonImmutable::today()->addDays(30)->toDateString();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:medicine_categories,id'],
            'stock_status' => ['nullable', Rule::in(['all', 'low_stock', 'out_of_stock', 'expiring_soon', 'expired'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'archived', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Medicine::query()
            ->with('category:id,name')
            ->withSum(['batches as stock_quantity' => fn ($q) => $q->where(function ($q) use ($today): void {
                $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today);
            })], 'quantity_remaining')
            ->withCount(['batches as active_batches_count' => fn ($q) => $q->where('quantity_remaining', '>', 0)])
            ->withMin(['batches as earliest_expiry' => fn ($q) => $q->where('quantity_remaining', '>', 0)->whereNotNull('expires_at')], 'expires_at');

        $status = $filters['status'] ?? 'active';
        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } else {
            $query->where('is_active', true);
        }

        if (! empty($filters['category_id'])) {
            $query->where('medicine_category_id', $filters['category_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('generic_name', 'like', '%'.$search.'%')
                    ->orWhere('brand', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%');
            });
        }

        $stockStatus = $filters['stock_status'] ?? 'all';
        if ($stockStatus === 'expiring_soon') {
            $query->whereHas('batches', fn ($q) => $q->where('quantity_remaining', '>', 0)->whereNotNull('expires_at')->whereDate('expires_at', '>=', $today)->whereDate('expires_at', '<=', $soonCutoff));
        } elseif ($stockStatus === 'expired') {
            $query->whereHas('batches', fn ($q) => $q->where('quantity_remaining', '>', 0)->whereNotNull('expires_at')->whereDate('expires_at', '<', $today));
        }

        $medicines = $query->orderBy('name')->get()->map(fn (Medicine $medicine): array => $this->formatMedicine($medicine));

        if ($stockStatus === 'low_stock') {
            $medicines = $medicines->filter(fn (array $m): bool => $m['stock_quantity'] <= $m['reorder_level'])->values();
        } elseif ($stockStatus === 'out_of_stock') {
            $medicines = $medicines->filter(fn (array $m): bool => $m['stock_quantity'] <= 0)->values();
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = max(1, (int) $request->integer('page', 1));
        $total = $medicines->count();
        $sliced = $medicines->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $sliced,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'medicine_category_id' => ['required', 'integer', 'exists:medicine_categories,id'],
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('medicines', 'sku')],
            'name' => ['required', 'string', 'max:160'],
            'generic_name' => ['nullable', 'string', 'max:160'],
            'brand' => ['nullable', 'string', 'max:120'],
            'dosage_form' => ['nullable', 'string', 'max:60'],
            'unit' => ['required', 'string', 'max:40'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'manufacturer' => ['nullable', 'string', 'max:160'],
            'requires_prescription' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $medicine = DB::transaction(function () use ($validated): Medicine {
            $hasSku = ! empty($validated['sku']);
            $medicine = Medicine::create([
                ...$validated,
                'sku' => $hasSku ? trim((string) $validated['sku']) : 'T-'.Str::random(16),
                'requires_prescription' => (bool) ($validated['requires_prescription'] ?? false),
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);

            if (! $hasSku) {
                $medicine->update(['sku' => sprintf('MED-%s-%05d', now()->format('Y'), $medicine->id)]);
            }

            return $medicine;
        });

        $medicine->load('category:id,name');

        return response()->json([
            'message' => 'Medicine created successfully.',
            'data' => $this->formatMedicine($medicine),
        ], 201);
    }

    public function show(string $medicine): JsonResponse
    {
        $today = CarbonImmutable::today()->toDateString();

        $record = Medicine::withTrashed()
            ->with([
                'category:id,name',
                'batches' => fn ($q) => $q->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderByDesc('received_at'),
                'inventoryTransactions' => fn ($q) => $q->with('performer:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->limit(30),
            ])
            ->withSum(['batches as stock_quantity' => fn ($q) => $q->where(function ($q) use ($today): void {
                $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today);
            })], 'quantity_remaining')
            ->findOrFail($medicine);

        return response()->json([
            'data' => [
                ...$this->formatMedicine($record),
                'batches' => $record->batches->map(fn (MedicineBatch $batch): array => [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'received_at' => $batch->received_at?->toDateString(),
                    'expires_at' => $batch->expires_at?->toDateString(),
                    'quantity_received' => (float) $batch->quantity_received,
                    'quantity_remaining' => (float) $batch->quantity_remaining,
                    'unit_cost' => (float) $batch->unit_cost,
                    'is_expired' => $batch->expires_at !== null && $batch->expires_at->toDateString() < $today,
                ])->values(),
                'transactions' => $record->inventoryTransactions->map(fn (InventoryTransaction $tx): array => [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'quantity_change' => (float) $tx->quantity_change,
                    'unit_cost' => (float) $tx->unit_cost,
                    'reference_number' => $tx->reference_number,
                    'notes' => $tx->notes,
                    'occurred_at' => $tx->occurred_at?->toISOString(),
                    'performer_name' => $tx->performer?->name,
                ])->values(),
            ],
        ]);
    }

    public function update(Request $request, Medicine $medicine): JsonResponse
    {
        $validated = $request->validate([
            'medicine_category_id' => ['sometimes', 'required', 'integer', 'exists:medicine_categories,id'],
            'sku' => ['sometimes', 'required', 'string', 'max:60', Rule::unique('medicines', 'sku')->ignore($medicine->id)],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'generic_name' => ['nullable', 'string', 'max:160'],
            'brand' => ['nullable', 'string', 'max:120'],
            'dosage_form' => ['nullable', 'string', 'max:60'],
            'unit' => ['sometimes', 'required', 'string', 'max:40'],
            'reorder_level' => ['sometimes', 'required', 'numeric', 'min:0'],
            'unit_cost' => ['sometimes', 'required', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'manufacturer' => ['nullable', 'string', 'max:160'],
            'requires_prescription' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $medicine->update($validated);
        $medicine->load('category:id,name');

        return response()->json([
            'message' => 'Medicine updated successfully.',
            'data' => $this->formatMedicine($medicine->fresh(['category:id,name'])),
        ]);
    }

    public function destroy(Medicine $medicine): JsonResponse
    {
        $medicine->delete();

        return response()->json(['message' => 'Medicine archived.']);
    }

    public function receive(Request $request, Medicine $medicine): JsonResponse
    {
        $validated = $request->validate([
            'batch_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('medicine_batches', 'batch_number')->where(fn ($q) => $q->where('medicine_id', $medicine->id)),
            ],
            'received_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'quantity_received' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $batch = $this->inventory->receiveStock($medicine, $validated, $request->user(), $request);

        return response()->json([
            'message' => 'Stock batch received successfully.',
            'data' => $batch,
        ], 201);
    }

    public function adjust(Request $request, Medicine $medicine): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['adjustment_in', 'adjustment_out', 'expired', 'damaged', 'return'])],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'medicine_batch_id' => ['nullable', 'integer', 'exists:medicine_batches,id'],
            'reference_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $transaction = $this->inventory->adjustStock($medicine, $validated, $request->user(), $request);

        return response()->json([
            'message' => 'Inventory stock adjusted successfully.',
            'data' => $transaction,
        ], 201);
    }

    public function transactions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'medicine_id' => ['nullable', 'integer', 'exists:medicines,id'],
            'type' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = InventoryTransaction::query()
            ->with(['medicine:id,name,sku,unit', 'batch:id,batch_number,expires_at', 'performer:id,name']);

        if (! empty($filters['medicine_id'])) {
            $query->where('medicine_id', $filters['medicine_id']);
        }

        if (! empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->where('reference_number', 'like', '%'.$search.'%')
                    ->orWhere('notes', 'like', '%'.$search.'%')
                    ->orWhereHas('medicine', fn ($mq) => $mq->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%'));
            });
        }

        $paginator = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (InventoryTransaction $tx): array => [
                'id' => $tx->id,
                'medicine_id' => $tx->medicine_id,
                'medicine_name' => $tx->medicine?->name,
                'medicine_sku' => $tx->medicine?->sku,
                'unit' => $tx->medicine?->unit,
                'batch_number' => $tx->batch?->batch_number,
                'type' => $tx->type,
                'quantity_change' => (float) $tx->quantity_change,
                'unit_cost' => (float) $tx->unit_cost,
                'reference_number' => $tx->reference_number,
                'notes' => $tx->notes,
                'occurred_at' => $tx->occurred_at?->toISOString(),
                'performer_name' => $tx->performer?->name,
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMedicine(Medicine $medicine): array
    {
        $stock = round((float) ($medicine->stock_quantity ?? 0), 3);
        $reorderLevel = round((float) $medicine->reorder_level, 3);

        return [
            'id' => $medicine->id,
            'medicine_category_id' => $medicine->medicine_category_id,
            'category_name' => $medicine->category?->name,
            'sku' => $medicine->sku,
            'name' => $medicine->name,
            'generic_name' => $medicine->generic_name,
            'brand' => $medicine->brand,
            'dosage_form' => $medicine->dosage_form,
            'unit' => $medicine->unit,
            'reorder_level' => $reorderLevel,
            'unit_cost' => (float) $medicine->unit_cost,
            'selling_price' => (float) $medicine->selling_price,
            'manufacturer' => $medicine->manufacturer,
            'requires_prescription' => (bool) $medicine->requires_prescription,
            'is_active' => (bool) $medicine->is_active,
            'stock_quantity' => $stock,
            'is_low_stock' => $stock <= $reorderLevel,
            'is_out_of_stock' => $stock <= 0,
            'active_batches_count' => (int) ($medicine->active_batches_count ?? 0),
            'earliest_expiry' => $medicine->earliest_expiry,
            'deleted_at' => $medicine->deleted_at?->toISOString(),
        ];
    }
}
