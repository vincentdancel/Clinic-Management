<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Patient;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = CarbonImmutable::today();
        $now = CarbonImmutable::now();
        $monthStart = $today->startOfMonth()->subMonths(5);

        $revenueByMonth = Payment::query()
            ->where('status', 'completed')
            ->whereBetween('paid_at', [$monthStart, $today->endOfDay()])
            ->get(['paid_at', 'amount'])
            ->groupBy(fn (Payment $payment): string => $payment->paid_at->format('Y-m'))
            ->map(fn ($group): float => round((float) $group->sum('amount'), 2));

        $monthlyRevenue = collect(range(0, 5))->map(function (int $offset) use ($monthStart, $revenueByMonth): array {
            $month = $monthStart->addMonths($offset);
            $key = $month->format('Y-m');

            return [
                'month' => $key,
                'label' => $month->format('M'),
                'total' => (float) ($revenueByMonth->get($key) ?? 0),
            ];
        });

        $appointmentStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'];
        $appointmentCounts = Appointment::query()
            ->whereBetween('scheduled_at', [$today->startOfDay(), $today->endOfDay()])
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $activeMedicines = Medicine::query()
            ->where('is_active', true)
            ->withSum(['batches as stock_quantity' => fn ($query) => $query->where(function ($query) use ($today): void {
                $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today->toDateString());
            })], 'quantity_remaining')
            ->get(['id', 'name', 'sku', 'unit', 'reorder_level', 'unit_cost'])
            ->map(fn (Medicine $medicine): array => [
                'id' => $medicine->id,
                'name' => $medicine->name,
                'sku' => $medicine->sku,
                'unit' => $medicine->unit,
                'stock' => (float) ($medicine->stock_quantity ?? 0),
                'reorder_level' => (float) $medicine->reorder_level,
                'unit_cost' => (float) $medicine->unit_cost,
            ]);

        $lowStockMedicines = $activeMedicines
            ->filter(fn (array $medicine): bool => $medicine['stock'] <= $medicine['reorder_level'])
            ->sortBy('stock');
        $lowStockMedicineCount = $lowStockMedicines->count();
        $outOfStockCount = $activeMedicines->filter(fn (array $medicine): bool => $medicine['stock'] <= 0)->count();
        $totalStockUnits = round((float) $activeMedicines->sum('stock'), 2);
        $totalInventoryValue = round((float) $activeMedicines->sum(fn (array $m): float => $m['stock'] * $m['unit_cost']), 2);
        $expiredBatchesCount = MedicineBatch::query()
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $today->toDateString())
            ->count();

        $lowStockAlerts = $lowStockMedicines
            ->take(8)
            ->map(fn (array $m): array => [
                'id' => $m['id'],
                'name' => $m['name'],
                'sku' => $m['sku'],
                'unit' => $m['unit'],
                'stock' => $m['stock'],
                'reorder_level' => $m['reorder_level'],
            ])
            ->values();

        $pendingBalance = DB::table('invoices')
            ->leftJoinSub(
                DB::table('payments')->select('invoice_id', DB::raw('SUM(amount) as total_paid'))
                    ->where('status', 'completed')->groupBy('invoice_id'),
                'completed_payments',
                'completed_payments.invoice_id',
                '=',
                'invoices.id'
            )
            ->whereNotIn('invoices.status', ['paid', 'cancelled', 'void'])
            ->sum(DB::raw('CASE WHEN invoices.total_amount > COALESCE(completed_payments.total_paid, 0) THEN invoices.total_amount - COALESCE(completed_payments.total_paid, 0) ELSE 0 END'));

        $invoiceDistributionRaw = Invoice::query()
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total_amount'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $paymentStatusDistribution = collect(Invoice::STATUSES)->map(fn (string $status): array => [
            'status' => $status,
            'label' => ucwords(str_replace('_', ' ', $status)),
            'count' => (int) ($invoiceDistributionRaw->get($status)?->count ?? 0),
            'total_amount' => round((float) ($invoiceDistributionRaw->get($status)?->total_amount ?? 0), 2),
        ])->values();

        return response()->json([
            'stats' => [
                'total_patients' => Patient::query()->count(),
                'total_doctors' => Employee::query()->whereHas('doctorSpecializations')->count(),
                'total_employees' => Employee::query()->count(),
                'total_appointments' => Appointment::query()->count(),
                'today_appointments' => Appointment::query()->whereBetween('scheduled_at', [$today->startOfDay(), $today->endOfDay()])->count(),
                'completed_consultations' => Consultation::query()->where('status', 'completed')->count(),
                'today_revenue' => (float) Payment::query()->where('status', 'completed')->whereDate('paid_at', $today)->sum('amount'),
                'pending_payments' => (float) $pendingBalance,
                'low_stock_medicines' => $lowStockMedicineCount,
            ],
            'recent_patients' => Patient::query()->latest()->limit(6)->get(['id', 'patient_number', 'first_name', 'last_name', 'created_at']),
            'upcoming_appointments' => Appointment::query()
                ->with(['patient:id,first_name,last_name', 'doctor:id,first_name,last_name'])
                ->whereBetween('scheduled_at', [$now, $today->addDays(7)->endOfDay()])
                ->whereNotIn('status', ['cancelled', 'no_show', 'completed'])
                ->orderBy('scheduled_at')->limit(6)->get(['id', 'patient_id', 'doctor_id', 'scheduled_at', 'status', 'reason']),
            'monthly_revenue' => $monthlyRevenue,
            'appointment_statistics' => collect($appointmentStatuses)->map(fn (string $status): array => [
                'status' => $status,
                'total' => (int) ($appointmentCounts->get($status) ?? 0),
            ]),
            'payment_status_distribution' => $paymentStatusDistribution,
            'inventory_statistics' => [
                'total_medicines' => Medicine::query()->count(),
                'active_medicines' => $activeMedicines->count(),
                'total_stock_units' => $totalStockUnits,
                'total_inventory_value' => $totalInventoryValue,
                'low_stock_count' => $lowStockMedicineCount,
                'out_of_stock_count' => $outOfStockCount,
                'expired_batches_count' => $expiredBatchesCount,
            ],
            'low_stock_alerts' => $lowStockAlerts,
        ]);
    }
}
