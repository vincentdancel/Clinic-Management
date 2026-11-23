<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PatientAppointmentRequest;
use App\Http\Requests\PatientPortalPasswordRequest;
use App\Http\Requests\PatientPortalProfileUpdateRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PatientAllergyResource;
use App\Http\Resources\PatientMedicalHistoryResource;
use App\Http\Resources\PatientPortalConsultationResource;
use App\Http\Resources\PatientPortalProfileResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PrescriptionResource;
use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\Consultation;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Services\AppointmentService;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PatientPortalController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly AppointmentService $appointments,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $patient = $this->resolvePatient($request);
        $now = CarbonImmutable::now();

        $upcomingAppointments = Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('scheduled_at', '>=', $now)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED])
            ->with(['doctor:id,employee_number,first_name,last_name,job_title,department_id', 'doctor.department:id,name'])
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get();

        $previousAppointments = Appointment::query()
            ->where('patient_id', $patient->id)
            ->where(function ($query) use ($now): void {
                $query->where('scheduled_at', '<', $now)
                    ->orWhereIn('status', [Appointment::STATUS_COMPLETED, Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW]);
            })
            ->with(['doctor:id,employee_number,first_name,last_name,job_title,department_id', 'doctor.department:id,name'])
            ->orderByDesc('scheduled_at')
            ->limit(5)
            ->get();

        $recentConsultations = Consultation::query()
            ->where('patient_id', $patient->id)
            ->where('status', Consultation::STATUS_COMPLETED)
            ->where('is_released_to_patient', true)
            ->with(['doctor:id,first_name,last_name,job_title,department_id', 'doctor.department:id,name', 'appointment:id,appointment_number,scheduled_at', 'vitalSigns', 'prescriptions.items'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $patient->load(['allergies', 'medicalHistories']);

        $activePrescriptions = Prescription::query()
            ->whereHas('consultation', function ($query) use ($patient): void {
                $query->where('patient_id', $patient->id)
                    ->where('status', Consultation::STATUS_COMPLETED)
                    ->where('is_released_to_patient', true);
            })
            ->whereIn('status', ['pending', 'active'])
            ->with(['prescriber:id,employee_number,first_name,last_name,job_title', 'items.medicine:id,name,generic_name,dosage_form,strength'])
            ->orderByDesc('prescribed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $outstandingInvoices = Invoice::query()
            ->where('patient_id', $patient->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIALLY_PAID])
            ->with(['payments'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $recentPayments = Payment::query()
            ->whereHas('invoice', fn ($query) => $query->where('patient_id', $patient->id))
            ->with(['invoice:id,invoice_number,total_amount,status,issued_at,patient_id'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $settings = ClinicSetting::allFormatted();

        return response()->json([
            'patient' => new PatientPortalProfileResource($patient),
            'upcoming_appointments' => AppointmentResource::collection($upcomingAppointments),
            'previous_appointments' => AppointmentResource::collection($previousAppointments),
            'recent_consultations' => PatientPortalConsultationResource::collection($recentConsultations),
            'medical_records' => [
                'allergies' => PatientAllergyResource::collection($patient->allergies),
                'medical_histories' => PatientMedicalHistoryResource::collection($patient->medicalHistories),
            ],
            'active_prescriptions' => PrescriptionResource::collection($activePrescriptions),
            'outstanding_invoices' => InvoiceResource::collection($outstandingInvoices),
            'recent_payments' => PaymentResource::collection($recentPayments),
            'settings' => [
                'patient_appointment_requests_enabled' => (bool) ($settings['patient_appointment_requests_enabled'] ?? false),
            ],
        ]);
    }

    public function profile(Request $request): PatientPortalProfileResource
    {
        $patient = $this->resolvePatient($request);
        Gate::authorize('view', $patient);

        return new PatientPortalProfileResource($patient);
    }

    public function updateProfile(PatientPortalProfileUpdateRequest $request): JsonResponse
    {
        $patient = $this->resolvePatient($request);
        Gate::authorize('update', $patient);

        $validated = $request->validated();
        $oldValues = $patient->only(['phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone']);
        $patient->update($validated);
        $newValues = $patient->only(['phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone']);

        $this->auditLogs->log('patient.profile_updated', $patient, $request->user(), $oldValues, $newValues, $request);

        return response()->json([
            'message' => 'Profile updated.',
            'data' => new PatientPortalProfileResource($patient->fresh()),
        ]);
    }

    public function updatePassword(PatientPortalPasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $patient = $this->resolvePatient($request);

        $user->update(['password' => $request->validated('password')]);
        $this->auditLogs->log('patient.password_changed', $patient, $user, null, ['user_id' => $user->id], $request);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function appointments(Request $request): JsonResponse
    {
        $patient = $this->resolvePatient($request);
        $now = CarbonImmutable::now();

        $upcoming = Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('scheduled_at', '>=', $now)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW, Appointment::STATUS_COMPLETED])
            ->with(['doctor:id,employee_number,first_name,last_name,job_title,department_id', 'doctor.department:id,name'])
            ->orderBy('scheduled_at')
            ->get();

        $history = Appointment::query()
            ->where('patient_id', $patient->id)
            ->where(function ($query) use ($now): void {
                $query->where('scheduled_at', '<', $now)
                    ->orWhereIn('status', [Appointment::STATUS_COMPLETED, Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW]);
            })
            ->with(['doctor:id,employee_number,first_name,last_name,job_title,department_id', 'doctor.department:id,name'])
            ->orderByDesc('scheduled_at')
            ->get();

        return response()->json([
            'upcoming' => AppointmentResource::collection($upcoming),
            'history' => AppointmentResource::collection($history),
        ]);
    }

    public function requestAppointment(PatientAppointmentRequest $request): JsonResponse
    {
        $settings = ClinicSetting::allFormatted();
        if (! ($settings['patient_appointment_requests_enabled'] ?? false)) {
            return response()->json([
                'message' => 'Patient self-service appointment requests are currently disabled. Please contact the clinic.',
            ], 403);
        }

        $patient = $this->resolvePatient($request);
        $doctor = Employee::query()->whereHas('doctorSpecializations')->findOrFail($request->validated('doctor_id'));

        $appointment = $this->appointments->schedule([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $request->validated('scheduled_at'),
            'reason' => $request->validated('reason'),
            'status' => Appointment::STATUS_PENDING,
        ], $request->user());

        $this->auditLogs->log('patient.appointment_requested', $appointment, $request->user(), null, ['appointment_id' => $appointment->id], $request);

        return (new AppointmentResource($appointment->load(['doctor.department'])))->response()->setStatusCode(201);
    }

    public function consultations(Request $request): mixed
    {
        $patient = $this->resolvePatient($request);

        $consultations = Consultation::query()
            ->where('patient_id', $patient->id)
            ->where('status', Consultation::STATUS_COMPLETED)
            ->where('is_released_to_patient', true)
            ->with(['doctor.department', 'appointment', 'vitalSigns', 'prescriptions.items.medicine'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(15);

        return PatientPortalConsultationResource::collection($consultations);
    }

    public function showConsultation(Request $request, Consultation $consultation): PatientPortalConsultationResource
    {
        Gate::authorize('view', $consultation);

        $consultation->load(['doctor.department', 'appointment', 'vitalSigns', 'prescriptions.prescriber', 'prescriptions.items.medicine']);

        return new PatientPortalConsultationResource($consultation);
    }

    public function medicalRecords(Request $request): JsonResponse
    {
        $patient = $this->resolvePatient($request);
        $patient->load(['allergies', 'medicalHistories']);

        $releasedConsultations = Consultation::query()
            ->where('patient_id', $patient->id)
            ->where('status', Consultation::STATUS_COMPLETED)
            ->where('is_released_to_patient', true)
            ->with(['doctor.department', 'vitalSigns'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'allergies' => PatientAllergyResource::collection($patient->allergies),
            'medical_histories' => PatientMedicalHistoryResource::collection($patient->medicalHistories),
            'released_consultations' => PatientPortalConsultationResource::collection($releasedConsultations),
        ]);
    }

    public function prescriptions(Request $request): mixed
    {
        $patient = $this->resolvePatient($request);

        $prescriptions = Prescription::query()
            ->whereHas('consultation', function ($query) use ($patient): void {
                $query->where('patient_id', $patient->id)
                    ->where('status', Consultation::STATUS_COMPLETED)
                    ->where('is_released_to_patient', true);
            })
            ->with(['prescriber:id,employee_number,first_name,last_name,job_title', 'items.medicine'])
            ->orderByDesc('prescribed_at')
            ->orderByDesc('id')
            ->paginate(15);

        return PrescriptionResource::collection($prescriptions);
    }

    public function invoices(Request $request): mixed
    {
        $patient = $this->resolvePatient($request);

        $invoices = Invoice::query()
            ->where('patient_id', $patient->id)
            ->with(['items', 'payments'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(15);

        return InvoiceResource::collection($invoices);
    }

    public function showInvoice(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return new InvoiceResource($invoice->load(['items', 'payments', 'appointment', 'consultation.doctor']));
    }

    public function payments(Request $request): mixed
    {
        $patient = $this->resolvePatient($request);

        $payments = Payment::query()
            ->whereHas('invoice', fn ($query) => $query->where('patient_id', $patient->id))
            ->with(['invoice:id,invoice_number,total_amount,status,issued_at,patient_id'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15);

        return PaymentResource::collection($payments);
    }

    private function resolvePatient(Request $request): Patient
    {
        $patient = $request->user()?->patient;
        abort_unless($patient && ! $patient->trashed(), 403, 'No active patient record is linked to this account.');

        return $patient;
    }
}
