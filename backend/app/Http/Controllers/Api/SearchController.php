<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json([
                'patients' => [],
                'appointments' => [],
                'invoices' => [],
                'medicines' => [],
            ]);
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $like = "%{$escaped}%";
        $user = $request->user();

        $patients = [];
        if ($user->hasPermission('patients.view')) {
            $patients = Patient::query()
                ->where(function ($builder) use ($like): void {
                    $builder
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('patient_number', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->limit(5)
                ->get()
                ->map(fn (Patient $patient): array => [
                    'id' => $patient->id,
                    'title' => trim("{$patient->first_name} {$patient->last_name}"),
                    'subtitle' => collect([$patient->patient_number, $patient->phone ?: $patient->email])->filter()->implode(' · '),
                    'to' => "/patients/{$patient->id}",
                ])
                ->all();
        }

        $appointments = [];
        if ($user->hasPermission('appointments.view')) {
            $appointments = Appointment::query()
                ->with('patient:id,first_name,last_name')
                ->where(function ($builder) use ($like): void {
                    $builder
                        ->where('appointment_number', 'like', $like)
                        ->orWhere('reason', 'like', $like)
                        ->orWhereHas('patient', function ($patientQuery) use ($like): void {
                            $patientQuery
                                ->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like);
                        });
                })
                ->orderByDesc('scheduled_at')
                ->limit(5)
                ->get()
                ->map(fn (Appointment $appointment): array => [
                    'id' => $appointment->id,
                    'title' => collect([
                        $appointment->appointment_number,
                        $appointment->patient ? trim("{$appointment->patient->first_name} {$appointment->patient->last_name}") : null,
                    ])->filter()->implode(' · '),
                    'subtitle' => collect([
                        $appointment->scheduled_at?->format('M j, Y g:i A'),
                        str_replace('_', ' ', ucfirst($appointment->status)),
                    ])->filter()->implode(' · '),
                    'to' => '/appointments',
                ])
                ->all();
        }

        $invoices = [];
        if ($user->hasPermission('billing.view')) {
            $invoices = Invoice::query()
                ->with('patient:id,first_name,last_name')
                ->where(function ($builder) use ($like): void {
                    $builder
                        ->where('invoice_number', 'like', $like)
                        ->orWhereHas('patient', function ($patientQuery) use ($like): void {
                            $patientQuery
                                ->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like);
                        });
                })
                ->orderByDesc('issued_at')
                ->limit(5)
                ->get()
                ->map(fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'title' => collect([
                        $invoice->invoice_number,
                        $invoice->patient ? trim("{$invoice->patient->first_name} {$invoice->patient->last_name}") : null,
                    ])->filter()->implode(' · '),
                    'subtitle' => number_format((float) $invoice->total_amount, 2).' · '.str_replace('_', ' ', ucfirst($invoice->status)),
                    'to' => "/billing/invoices/{$invoice->id}",
                ])
                ->all();
        }

        $medicines = [];
        if ($user->hasPermission('inventory.manage')) {
            $medicines = Medicine::query()
                ->where(function ($builder) use ($like): void {
                    $builder
                        ->where('name', 'like', $like)
                        ->orWhere('generic_name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('brand', 'like', $like);
                })
                ->orderBy('name')
                ->limit(5)
                ->get()
                ->map(fn (Medicine $medicine): array => [
                    'id' => $medicine->id,
                    'title' => $medicine->name,
                    'subtitle' => collect([$medicine->sku, $medicine->generic_name ?: $medicine->dosage_form])->filter()->implode(' · '),
                    'to' => '/inventory',
                ])
                ->all();
        }

        return response()->json([
            'patients' => $patients,
            'appointments' => $appointments,
            'invoices' => $invoices,
            'medicines' => $medicines,
        ]);
    }
}
