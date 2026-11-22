<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentIndexRequest;
use App\Http\Requests\AppointmentRescheduleRequest;
use App\Http\Requests\AppointmentStatusRequest;
use App\Http\Requests\AppointmentStoreRequest;
use App\Http\Requests\DoctorScheduleStoreRequest;
use App\Http\Resources\AppointmentHistoryResource;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\DoctorScheduleResource;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Employee;
use App\Models\Patient;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $appointments)
    {
    }

    public function index(AppointmentIndexRequest $request): mixed
    {
        $filters = $request->validated();
        $query = Appointment::query()->with([
            'patient',
            'doctor.department',
            'doctor.doctorSpecializations',
            'creator',
        ]);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['doctor_id'])) {
            $query->where('doctor_id', $filters['doctor_id']);
        }

        if (! empty($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('scheduled_at', '>=', CarbonImmutable::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('scheduled_at', '<=', CarbonImmutable::parse($filters['date_to'])->endOfDay());
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->where('appointment_number', 'like', '%'.$search.'%')
                    ->orWhere('reason', 'like', '%'.$search.'%')
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

        return AppointmentResource::collection($query->orderBy('scheduled_at')->paginate($perPage));
    }

    public function options(): JsonResponse
    {
        $doctors = Employee::query()
            ->with(['department:id,name', 'doctorSpecializations:id,name', 'schedules' => fn ($query) => $query->where('is_active', true)->orderBy('day_of_week')->orderBy('starts_at')])
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
                'specializations' => $doctor->doctorSpecializations->map(fn ($spec): array => [
                    'id' => $spec->id,
                    'name' => $spec->name,
                    'is_primary' => (bool) $spec->pivot->is_primary,
                ])->values(),
                'schedules' => DoctorScheduleResource::collection($doctor->schedules),
            ]);

        $patients = Patient::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(100)
            ->get(['id', 'patient_number', 'first_name', 'last_name', 'phone', 'email'])
            ->map(fn (Patient $patient): array => [
                'id' => $patient->id,
                'patient_number' => $patient->patient_number,
                'full_name' => trim($patient->first_name.' '.$patient->last_name),
                'phone' => $patient->phone,
            ]);

        return response()->json([
            'doctors' => $doctors,
            'patients' => $patients,
            'statuses' => collect(Appointment::STATUSES)->map(fn (string $status): array => [
                'value' => $status,
                'label' => ucwords(str_replace('_', ' ', $status)),
            ]),
        ]);
    }

    public function availableSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doctor_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:240'],
            'exclude_appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        $this->appointments->validateAvailableDoctor((int) $validated['doctor_id']);

        $slots = $this->appointments->getAvailableSlots(
            (int) $validated['doctor_id'],
            $validated['date'],
            (int) ($validated['duration_minutes'] ?? 30),
            isset($validated['exclude_appointment_id']) ? (int) $validated['exclude_appointment_id'] : null,
        );

        return response()->json(['slots' => $slots]);
    }

    public function store(AppointmentStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $appointment = DB::transaction(function () use ($data, $request): Appointment {
            $patient = $this->appointments->validateActivePatient((int) $data['patient_id']);
            $doctor = $this->appointments->validateAvailableDoctor((int) $data['doctor_id']);
            $scheduledAt = CarbonImmutable::parse($data['scheduled_at']);
            $duration = (int) ($data['duration_minutes'] ?? 30);

            $scheduleId = $this->appointments->validateDoctorAvailability($doctor->id, $scheduledAt, $duration);
            $status = $data['status'] ?? Appointment::STATUS_PENDING;

            $appointment = Appointment::create([
                'appointment_number' => 'TEMP-'.Str::uuid(),
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'doctor_schedule_id' => $scheduleId,
                'created_by' => $request->user()?->id,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $duration,
                'status' => $status,
                'reason' => trim($data['reason']),
            ]);

            $appointment->update([
                'appointment_number' => sprintf('APT-%s-%06d', now()->format('Y'), $appointment->id),
            ]);

            $this->appointments->recordHistory(
                $appointment,
                $request->user()?->id,
                'created',
                null,
                $status,
                null,
                $scheduledAt->toDateTimeString(),
                'Appointment scheduled.',
            );

            return $appointment;
        });

        return (new AppointmentResource($appointment->load(['patient', 'doctor.department', 'doctor.doctorSpecializations', 'creator', 'histories.actor'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $appointment): AppointmentResource
    {
        $record = Appointment::query()
            ->with(['patient', 'doctor.department', 'doctor.doctorSpecializations', 'creator', 'histories.actor'])
            ->findOrFail($appointment);

        return new AppointmentResource($record);
    }

    public function update(AppointmentRescheduleRequest $request, string $appointment): AppointmentResource
    {
        return $this->reschedule($request, $appointment);
    }

    public function reschedule(AppointmentRescheduleRequest $request, string $appointment): AppointmentResource
    {
        $data = $request->validated();

        $record = DB::transaction(function () use ($data, $appointment, $request): Appointment {
            $record = Appointment::query()->findOrFail($appointment);

            if (in_array($record->status, [Appointment::STATUS_COMPLETED, Appointment::STATUS_CANCELLED], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Completed or cancelled appointments cannot be rescheduled.'],
                ]);
            }

            $doctorId = (int) ($data['doctor_id'] ?? $record->doctor_id);
            $this->appointments->validateAvailableDoctor($doctorId);

            $newScheduledAt = CarbonImmutable::parse($data['scheduled_at']);
            $newDuration = (int) ($data['duration_minutes'] ?? $record->duration_minutes);
            $scheduleId = $this->appointments->validateDoctorAvailability($doctorId, $newScheduledAt, $newDuration, $record->id);

            $oldScheduledAt = $record->scheduled_at?->toDateTimeString();

            $record->update([
                'doctor_id' => $doctorId,
                'doctor_schedule_id' => $scheduleId,
                'scheduled_at' => $newScheduledAt,
                'duration_minutes' => $newDuration,
                'reason' => array_key_exists('reason', $data) && $data['reason'] !== null ? trim($data['reason']) : $record->reason,
            ]);

            $this->appointments->recordHistory(
                $record,
                $request->user()?->id,
                'rescheduled',
                $record->status,
                $record->status,
                $oldScheduledAt,
                $newScheduledAt->toDateTimeString(),
                $data['notes'] ?? 'Appointment rescheduled.',
            );

            return $record;
        });

        return new AppointmentResource($record->fresh()->load(['patient', 'doctor.department', 'doctor.doctorSpecializations', 'creator', 'histories.actor']));
    }

    public function updateStatus(AppointmentStatusRequest $request, string $appointment): AppointmentResource
    {
        $data = $request->validated();

        $record = DB::transaction(function () use ($data, $appointment, $request): Appointment {
            $record = Appointment::query()->findOrFail($appointment);
            $oldStatus = $record->status;
            $newStatus = $data['status'];

            if ($oldStatus === $newStatus) {
                throw ValidationException::withMessages([
                    'status' => ['Appointment is already marked as '.str_replace('_', ' ', $newStatus).'.'],
                ]);
            }

            if ($oldStatus === Appointment::STATUS_CANCELLED && $newStatus !== Appointment::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Cancelled appointments can only be reopened to pending or rebooked as a new appointment.'],
                ]);
            }

            // If reopening a cancelled/no_show appointment to an active status, ensure no conflict exists now
            if (in_array($oldStatus, Appointment::NON_BLOCKING_STATUSES, true) && ! in_array($newStatus, Appointment::NON_BLOCKING_STATUSES, true)) {
                $this->appointments->validateDoctorAvailability(
                    $record->doctor_id,
                    CarbonImmutable::instance($record->scheduled_at),
                    (int) $record->duration_minutes,
                    $record->id,
                );
            }

            $updates = ['status' => $newStatus];
            if ($newStatus === Appointment::STATUS_CANCELLED) {
                $updates['cancellation_reason'] = $data['cancellation_reason'] ?? $record->cancellation_reason;
            }

            $record->update($updates);

            $this->appointments->recordHistory(
                $record,
                $request->user()?->id,
                $newStatus === Appointment::STATUS_CANCELLED ? 'cancelled' : 'status_changed',
                $oldStatus,
                $newStatus,
                null,
                null,
                $data['notes'] ?? $data['cancellation_reason'] ?? 'Status updated.',
            );

            return $record;
        });

        return new AppointmentResource($record->fresh()->load(['patient', 'doctor.department', 'doctor.doctorSpecializations', 'creator', 'histories.actor']));
    }

    public function cancel(Request $request, string $appointment): AppointmentResource
    {
        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = DB::transaction(function () use ($validated, $appointment, $request): Appointment {
            $record = Appointment::query()->findOrFail($appointment);

            if ($record->status === Appointment::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => ['This appointment is already cancelled.'],
                ]);
            }

            if ($record->status === Appointment::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => ['Completed appointments cannot be cancelled.'],
                ]);
            }

            $oldStatus = $record->status;
            $reason = $validated['cancellation_reason'] ?? null;

            $record->update([
                'status' => Appointment::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
            ]);

            $this->appointments->recordHistory(
                $record,
                $request->user()?->id,
                'cancelled',
                $oldStatus,
                Appointment::STATUS_CANCELLED,
                null,
                null,
                $reason ?: 'Appointment cancelled.',
            );

            return $record;
        });

        return new AppointmentResource($record->fresh()->load(['patient', 'doctor.department', 'doctor.doctorSpecializations', 'creator', 'histories.actor']));
    }

    public function history(string $appointment): JsonResponse
    {
        $record = Appointment::query()
            ->with(['histories.actor'])
            ->findOrFail($appointment);

        $patientAppointments = Appointment::query()
            ->with(['doctor:id,first_name,last_name,employee_number'])
            ->where('patient_id', $record->patient_id)
            ->orderByDesc('scheduled_at')
            ->limit(20)
            ->get();

        return response()->json([
            'histories' => AppointmentHistoryResource::collection($record->histories),
            'patient_appointments' => AppointmentResource::collection($patientAppointments),
        ]);
    }

    public function doctorSchedules(Request $request): mixed
    {
        $validated = $request->validate([
            'doctor_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $query = DoctorSchedule::query()->orderBy('day_of_week')->orderBy('starts_at');

        if (! empty($validated['doctor_id'])) {
            $query->where('employee_id', $validated['doctor_id']);
        }

        return DoctorScheduleResource::collection($query->get());
    }

    public function storeDoctorSchedule(DoctorScheduleStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->appointments->validateAvailableDoctor((int) $data['employee_id']);

        $schedule = DoctorSchedule::create([
            'employee_id' => $data['employee_id'],
            'day_of_week' => $data['day_of_week'],
            'starts_at' => $data['starts_at'].':00',
            'ends_at' => $data['ends_at'].':00',
            'slot_minutes' => $data['slot_minutes'] ?? 30,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return (new DoctorScheduleResource($schedule))->response()->setStatusCode(201);
    }

    public function destroyDoctorSchedule(string $schedule): JsonResponse
    {
        DoctorSchedule::query()->findOrFail($schedule)->delete();

        return response()->json(['message' => 'Doctor schedule removed.']);
    }
}

