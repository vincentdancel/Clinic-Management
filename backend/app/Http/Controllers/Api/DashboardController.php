<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Employee;
use App\Models\Medicine;
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
            ->selectRaw('YEAR(paid_at) as year, MONTH(paid_at) as month, SUM(amount) as total')
            ->groupByRaw('YEAR(paid_at), MONTH(paid_at)')
            ->get()
            ->keyBy(fn (Payment $payment): string => sprintf('%04d-%02d', $payment->year, $payment->month));

        $monthlyRevenue = collect(range(0, 5))->map(function (int $offset) use ($monthStart, $revenueByMonth): array {
            $month = $monthStart->addMonths($offset);
            $key = $month->format('Y-m');

            return [
                'month' => $key,
                'label' => $month->format('M'),
                'total' => (float) ($revenueByMonth->get($key)?->total ?? 0),
            ];
        });

        $appointmentStatuses = ['scheduled', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'];
        $appointmentCounts = Appointment::query()
            ->whereBetween('scheduled_at', [$today->startOfDay(), $today->endOfDay()])
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $lowStockMedicines = Medicine::query()
            ->where('is_active', true)
            ->withSum(['batches as stock_quantity' => fn ($query) => $query->where(function ($query) use ($today): void {
                $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today->toDateString());
            })], 'quantity_remaining')
            ->get(['id', 'name', 'sku', 'unit', 'reorder_level'])
            ->map(fn (Medicine $medicine): array => [
                'id' => $medicine->id,
                'name' => $medicine->name,
                'sku' => $medicine->sku,
                'unit' => $medicine->unit,
                'stock' => (float) ($medicine->stock_quantity ?? 0),
                'reorder_level' => (float) $medicine->reorder_level,
            ])
            ->filter(fn (array $medicine): bool => $medicine['stock'] <= $medicine['reorder_level'])
            ->sortBy('stock');
        $lowStockMedicineCount = $lowStockMedicines->count();
        $lowStockMedicines = $lowStockMedicines
            ->take(8)
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
            ->sum(DB::raw('GREATEST(invoices.total_amount - COALESCE(completed_payments.total_paid, 0), 0)'));

        return response()->json([
            'stats' => [
                'total_patients' => Patient::query()->count(),
                'total_doctors' => Employee::query()->whereHas('doctorSpecializations')->count(),
                'total_employees' => Employee::query()->count(),
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
            'low_stock_alerts' => $lowStockMedicines,
        ]);
    }
}
