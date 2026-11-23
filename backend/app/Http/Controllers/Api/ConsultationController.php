<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultationIndexRequest;
use App\Http\Requests\ConsultationStoreRequest;
use App\Http\Requests\ConsultationUpdateRequest;
use App\Http\Requests\PrescriptionStoreRequest;
use App\Http\Requests\VitalSignStoreRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\ConsultationResource;
use App\Http\Resources\PatientResource;
use App\Http\Resources\PrescriptionResource;
use App\Http\Resources\VitalSignResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Employee;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Prescription;
use App\Services\AuditLogService;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsultationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly InventoryService $inventory,
    ) {
    }

    public function index(ConsultationIndexRequest $request): mixed
    {
        $filters = $request->validated();

        $query = Consultation::query()->with([
            'patient.allergies',
            'doctor.department',
            'doctor.doctorSpecializations',
            'appointment',
            'vitalSigns.recorder',
            'prescriptions.items',
        ]);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (! empty($filters['doctor_id'])) {
            $query->where('doctor_id', $filters['doctor_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('started_at', '>=', CarbonImmutable::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('started_at', '<=', CarbonImmutable::parse($filters['date_to'])->endOfDay());
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->where('consultation_number', 'like', '%'.$search.'%')
                    ->orWhere('chief_complaint', 'like', '%'.$search.'%')
                    ->orWhere('symptoms', 'like', '%'.$search.'%')
                    ->orWhere('diagnosis', 'like', '%'.$search.'%')
                    ->orWhereHas('patient', function ($patientQuery) use ($search): void {
                        $patientQuery->where('patient_number', 'like', '%'.$search.'%')
                            ->orWhere('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('doctor', function ($doctorQuery) use ($search): void {
                        $doctorQuery->where('employee_number', 'like', '%'.$search.'%')
                            ->orWhere('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%');
                    });
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 15);

        return ConsultationResource::collection($query->orderByDesc('started_at')->orderByDesc('id')->paginate($perPage));
    }

    public function options(): JsonResponse
    {
        $doctors = Employee::query()
            ->with(['department:id,name', 'doctorSpecializations:id,name'])
            ->where('employee_category', 'doctor')
            ->where('employment_status', 'active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Employee $doctor): array => [
                'id' => $doctor->id,
                'employee_number' => $doctor->employee_number,
                'full_name' => trim($doctor->first_name.' '.$doctor->last_name),
                'job_title' => $doctor->job_title,
                'department' => $doctor->department?->name,
            ]);

        $patients = Patient::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(200)
            ->get(['id', 'patient_number', 'first_name', 'last_name', 'gender', 'date_of_birth', 'phone'])
            ->map(fn (Patient $patient): array => [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'full_name' => trim($patient->first_name.' '.$patient->last_name),
                'gender' => $patient->gender,
                'age' => $patient->date_of_birth?->age,
                'phone' => $patient->phone,
            ]);

        $appointments = Appointment::query()
            ->with(['patient:id,patient_number,first_name,last_name', 'doctor:id,employee_number,first_name,last_name'])
            ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
            ->whereDoesntHave('consultation')
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'appointment_number' => $appointment->appointment_number,
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'scheduled_at' => $appointment->scheduled_at?->toISOString(),
                'reason' => $appointment->reason,
                'patient_name' => $appointment->patient ? trim($appointment->patient->first_name.' '.$appointment->patient->last_name) : null,
                'doctor_name' => $appointment->doctor ? trim($appointment->doctor->first_name.' '.$appointment->doctor->last_name) : null,
            ]);

        $medicines = Medicine::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'generic_name', 'dosage_form', 'unit']);

        return response()->json([
            'doctors' => $doctors,
            'patients' => $patients,
            'appointments' => $appointments,
            'medicines' => $medicines,
        ]);
    }

    public function store(ConsultationStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->assertClinicalDiagnoseAuthorization($request, $validated);

        $consultation = DB::transaction(function () use ($request, $validated): Consultation {
            $patientId = $validated['patient_id'] ?? null;
            $doctorId = $validated['doctor_id'] ?? null;

            if (! empty($validated['appointment_id'])) {
                $appointment = Appointment::query()->with(['patient', 'doctor'])->findOrFail($validated['appointment_id']);

                if (in_array($appointment->status, Appointment::NON_BLOCKING_STATUSES, true)) {
                    throw ValidationException::withMessages([
                        'appointment_id' => ['Cannot start a consultation for a cancelled or no-show appointment.'],
                    ]);
                }

                if ($patientId !== null && (int) $patientId !== $appointment->patient_id) {
                    throw ValidationException::withMessages([
                        'patient_id' => ['The selected patient does not match the linked appointment.'],
                    ]);
                }

                if ($doctorId !== null && (int) $doctorId !== $appointment->doctor_id) {
                    throw ValidationException::withMessages([
                        'doctor_id' => ['The selected doctor does not match the linked appointment.'],
                    ]);
                }

                $patientId = $appointment->patient_id;
                $doctorId = $appointment->doctor_id;
            }

            $status = $validated['status'] ?? Consultation::STATUS_IN_PROGRESS;
            $startedAt = isset($validated['started_at']) ? CarbonImmutable::parse($validated['started_at']) : now();

            $consultation = Consultation::create([
                'consultation_number' => 'T-'.Str::random(16),
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'recorded_by' => $request->user()->id,
                'appointment_id' => $validated['appointment_id'] ?? null,
                'started_at' => $startedAt,
                'ended_at' => $status === Consultation::STATUS_COMPLETED ? now() : null,
                'chief_complaint' => $validated['chief_complaint'] ?? null,
                'symptoms' => $validated['symptoms'] ?? null,
                'diagnosis' => $validated['diagnosis'] ?? null,
                'treatment_plan' => $validated['treatment_plan'] ?? null,
                'clinical_notes' => $validated['clinical_notes'] ?? null,
                'follow_up_date' => $validated['follow_up_date'] ?? null,
                'status' => $status,
            ]);

            $consultation->update([
                'consultation_number' => sprintf('CON-%s-%06d', now()->format('Y'), $consultation->id),
            ]);

            $this->auditLogs->log(
                event: 'consultation.created',
                auditable: $consultation,
                user: $request->user(),
                oldValues: null,
                newValues: Arr::only($consultation->toArray(), [
                    'consultation_number', 'patient_id', 'doctor_id', 'appointment_id',
                    'chief_complaint', 'symptoms', 'diagnosis', 'treatment_plan',
                    'clinical_notes', 'follow_up_date', 'status',
                ]),
                request: $request,
            );

            return $consultation;
        });

        return (new ConsultationResource($this->loadDetailRelations($consultation)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $consultation): ConsultationResource
    {
        $record = Consultation::query()->findOrFail($consultation);

        return new ConsultationResource($this->loadDetailRelations($record));
    }

    public function update(ConsultationUpdateRequest $request, string $consultation): ConsultationResource
    {
        $record = Consultation::query()->findOrFail($consultation);
        $validated = $request->validated();

        $this->assertClinicalDiagnoseAuthorization($request, $validated);

        $trackedFields = [
            'doctor_id', 'chief_complaint', 'symptoms', 'diagnosis',
            'treatment_plan', 'clinical_notes', 'follow_up_date', 'status',
        ];
        $oldValues = Arr::only($record->toArray(), $trackedFields);

        DB::transaction(function () use ($record, $validated, $request, $oldValues, $trackedFields): void {
            $updates = $validated;
            if (($validated['status'] ?? null) === Consultation::STATUS_COMPLETED && $record->ended_at === null) {
                $updates['ended_at'] = now();
            }

            $record->update($updates);

            if (($validated['status'] ?? null) === Consultation::STATUS_COMPLETED && $record->appointment_id) {
                Appointment::query()
                    ->whereKey($record->appointment_id)
                    ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
                    ->update(['status' => Appointment::STATUS_COMPLETED]);
            }

            $newValues = Arr::only($record->fresh()->toArray(), $trackedFields);
            $this->auditLogs->log(
                event: 'consultation.updated',
                auditable: $record,
                user: $request->user(),
                oldValues: $oldValues,
                newValues: $newValues,
                request: $request,
            );
        });

        return new ConsultationResource($this->loadDetailRelations($record->fresh()));
    }

    public function complete(Request $request, string $consultation): ConsultationResource
    {
        $record = Consultation::query()->findOrFail($consultation);
        $oldStatus = $record->status;

        DB::transaction(function () use ($record, $request, $oldStatus): void {
            $record->update([
                'status' => Consultation::STATUS_COMPLETED,
                'ended_at' => $record->ended_at ?? now(),
            ]);

            if ($record->appointment_id) {
                Appointment::query()
                    ->whereKey($record->appointment_id)
                    ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])
                    ->update(['status' => Appointment::STATUS_COMPLETED]);
            }

            $this->auditLogs->log(
                event: 'consultation.completed',
                auditable: $record,
                user: $request->user(),
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => Consultation::STATUS_COMPLETED, 'ended_at' => $record->ended_at?->toISOString()],
                request: $request,
            );
        });

        return new ConsultationResource($this->loadDetailRelations($record->fresh()));
    }

    public function storeVitalSigns(VitalSignStoreRequest $request, string $consultation): JsonResponse
    {
        $record = Consultation::query()->findOrFail($consultation);
        $validated = $request->validated();

        $vitalSign = DB::transaction(function () use ($record, $validated, $request) {
            $vital = $record->vitalSigns()->create([
                ...$validated,
                'recorded_by' => $request->user()->id,
                'measured_at' => $validated['measured_at'] ?? now(),
            ]);

            $this->auditLogs->log(
                event: 'vital_signs.recorded',
                auditable: $record,
                user: $request->user(),
                oldValues: null,
                newValues: [
                    'vital_sign_id' => $vital->id,
                    'systolic_bp' => $vital->systolic_bp,
                    'diastolic_bp' => $vital->diastolic_bp,
                    'heart_rate_bpm' => $vital->heart_rate_bpm,
                    'temperature_c' => $vital->temperature_c,
                    'weight_kg' => $vital->weight_kg,
                    'height_cm' => $vital->height_cm,
                    'bmi' => $vital->bmi,
                ],
                request: $request,
            );

            return $vital;
        });

        return (new VitalSignResource($vitalSign->load('recorder')))
            ->response()
            ->setStatusCode(201);
    }

    public function storePrescription(PrescriptionStoreRequest $request, string $consultation): JsonResponse
    {
        $record = Consultation::query()->findOrFail($consultation);
        $validated = $request->validated();

        $prescription = DB::transaction(function () use ($record, $validated, $request): Prescription {
            $prescribedBy = $request->user()->employee?->id ?? $record->doctor_id;

            $prescription = $record->prescriptions()->create([
                'prescribed_by' => $prescribedBy,
                'prescribed_at' => $validated['prescribed_at'] ?? now()->toDateString(),
                'notes' => $validated['notes'] ?? null,
                'status' => 'active',
            ]);

            foreach ($validated['items'] as $item) {
                $medicineName = $item['medicine_name'] ?? null;
                if (! $medicineName && ! empty($item['medicine_id'])) {
                    $medicineName = Medicine::query()->find($item['medicine_id'])?->name;
                }

                $prescription->items()->create([
                    'medicine_id' => $item['medicine_id'] ?? null,
                    'medicine_name' => $medicineName,
                    'dosage' => $item['dosage'],
                    'frequency' => $item['frequency'],
                    'duration' => $item['duration'] ?? (isset($item['duration_days']) ? $item['duration_days'].' days' : null),
                    'duration_days' => $item['duration_days'] ?? null,
                    'quantity' => $item['quantity'],
                    'quantity_unit' => $item['quantity_unit'] ?? 'units',
                    'instructions' => $item['instructions'] ?? null,
                ]);
            }

            $this->auditLogs->log(
                event: 'prescription.created',
                auditable: $record,
                user: $request->user(),
                oldValues: null,
                newValues: [
                    'prescription_id' => $prescription->id,
                    'items_count' => count($validated['items']),
                    'medicines' => collect($validated['items'])->pluck('medicine_name')->filter()->values()->all(),
                ],
                request: $request,
            );

            return $prescription;
        });

        return (new PrescriptionResource($prescription->load(['prescriber', 'items.medicine'])))
            ->response()
            ->setStatusCode(201);
    }

    public function updatePrescriptionStatus(Request $request, string $consultation, string $prescription): PrescriptionResource
    {
        $record = Consultation::query()->findOrFail($consultation);
        $rx = $record->prescriptions()->whereKey($prescription)->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['active', 'dispensed', 'cancelled'])],
        ]);

        $oldStatus = $rx->status;
        $newStatus = $validated['status'];

        if ($oldStatus === 'dispensed' && $newStatus !== 'dispensed') {
            throw ValidationException::withMessages([
                'status' => ['A dispensed prescription cannot be reverted or cancelled.'],
            ]);
        }

        if ($oldStatus === 'cancelled' && $newStatus === 'dispensed') {
            throw ValidationException::withMessages([
                'status' => ['A cancelled prescription cannot be dispensed.'],
            ]);
        }

        DB::transaction(function () use ($rx, $record, $oldStatus, $newStatus, $request): void {
            if ($newStatus === 'dispensed' && $oldStatus !== 'dispensed') {
                $this->inventory->deductForPrescription($rx, $request->user(), $request);
            }

            $rx->update(['status' => $newStatus]);

            $this->auditLogs->log(
                event: 'prescription.updated',
                auditable: $record,
                user: $request->user(),
                oldValues: ['prescription_id' => $rx->id, 'status' => $oldStatus],
                newValues: ['prescription_id' => $rx->id, 'status' => $newStatus],
                request: $request,
            );
        });

        return new PrescriptionResource($rx->fresh(['prescriber', 'items.medicine']));
    }

    public function patientHistory(string $patient): JsonResponse
    {
        $patientRecord = Patient::withTrashed()
            ->with(['allergies', 'medicalHistories'])
            ->findOrFail($patient);

        $consultations = Consultation::query()
            ->where('patient_id', $patientRecord->id)
            ->with([
                'doctor.department',
                'doctor.doctorSpecializations',
                'appointment',
                'vitalSigns.recorder',
                'prescriptions.prescriber',
                'prescriptions.items.medicine',
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'patient' => new PatientResource($patientRecord),
            'consultations' => ConsultationResource::collection($consultations),
        ]);
    }

    public function auditLogs(string $consultation): mixed
    {
        $record = Consultation::query()->findOrFail($consultation);

        return AuditLogResource::collection($record->auditLogs()->with('user:id,name')->get());
    }

    public function releaseToPatient(Request $request, string $consultation): ConsultationResource
    {
        $record = Consultation::query()->findOrFail($consultation);

        if ($record->status !== Consultation::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => ['Only completed consultations can be released to the patient portal.'],
            ]);
        }

        $oldValues = ['is_released_to_patient' => (bool) $record->is_released_to_patient];

        $record->update([
            'is_released_to_patient' => true,
            'released_to_patient_at' => now(),
            'released_to_patient_by' => $request->user()->id,
        ]);

        $this->auditLogs->log(
            event: 'consultation.released_to_patient',
            auditable: $record,
            user: $request->user(),
            oldValues: $oldValues,
            newValues: ['is_released_to_patient' => true],
            request: $request,
        );

        return new ConsultationResource($this->loadDetailRelations($record->fresh()));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertClinicalDiagnoseAuthorization(Request $request, array $payload): void
    {
        if ($request->user()?->hasPermission('clinical.diagnose')) {
            return;
        }

        $restrictedFields = ['diagnosis', 'treatment_plan', 'follow_up_date'];
        foreach ($restrictedFields as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== '') {
                abort(403, 'Only doctors or authorized clinicians can record diagnosis, treatment plans, or follow-up dates.');
            }
        }

        if (($payload['status'] ?? null) === Consultation::STATUS_COMPLETED) {
            abort(403, 'Only doctors or authorized clinicians can complete a consultation.');
        }
    }

    private function loadDetailRelations(Consultation $consultation): Consultation
    {
        return $consultation->load([
            'patient.allergies',
            'patient.medicalHistories',
            'doctor.department',
            'doctor.doctorSpecializations',
            'appointment',
            'recorder',
            'vitalSigns.recorder',
            'prescriptions.prescriber',
            'prescriptions.items.medicine',
        ]);
    }
}

