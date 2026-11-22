<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentHistory;
use App\Models\DoctorSchedule;
use App\Models\Employee;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    /**
     * Validate that the patient exists and is active (not archived).
     */
    public function validateActivePatient(int $patientId): Patient
    {
        $patient = Patient::query()->find($patientId);

        if (! $patient) {
            throw ValidationException::withMessages([
                'patient_id' => ['Selected patient does not exist or has been archived.'],
            ]);
        }

        return $patient;
    }

    /**
     * Validate that the doctor exists, is an active employee, and is categorized as a doctor.
     */
    public function validateAvailableDoctor(int $doctorId): Employee
    {
        $doctor = Employee::query()
            ->where('employee_category', 'doctor')
            ->where('employment_status', 'active')
            ->find($doctorId);

        if (! $doctor) {
            throw ValidationException::withMessages([
                'doctor_id' => ['Selected doctor is not available or not an active registered doctor.'],
            ]);
        }

        return $doctor;
    }

    /**
     * Validate doctor schedule availability and check for overlapping appointments.
     * Returns the matching DoctorSchedule ID if the doctor has configured schedules.
     */
    public function validateDoctorAvailability(
        int $doctorId,
        CarbonImmutable $scheduledAt,
        int $durationMinutes,
        ?int $excludeAppointmentId = null,
    ): ?int {
        $endAt = $scheduledAt->addMinutes($durationMinutes);
        $dateString = $scheduledAt->toDateString();
        $dayOfWeek = $scheduledAt->dayOfWeek;

        // 1. Check doctor's configured schedules if any active schedules exist for this doctor
        $hasAnySchedules = DoctorSchedule::query()
            ->where('employee_id', $doctorId)
            ->where('is_active', true)
            ->exists();

        $matchingScheduleId = null;

        if ($hasAnySchedules) {
            $daySchedules = DoctorSchedule::query()
                ->where('employee_id', $doctorId)
                ->where('is_active', true)
                ->where('day_of_week', $dayOfWeek)
                ->where(function ($query) use ($dateString): void {
                    $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $dateString);
                })
                ->where(function ($query) use ($dateString): void {
                    $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $dateString);
                })
                ->get();

            $matchingSchedule = $daySchedules->first(function (DoctorSchedule $schedule) use ($scheduledAt, $endAt, $dateString): bool {
                $windowStart = CarbonImmutable::parse($dateString.' '.$schedule->starts_at, $scheduledAt->getTimezone());
                $windowEnd = CarbonImmutable::parse($dateString.' '.$schedule->ends_at, $scheduledAt->getTimezone());

                return $scheduledAt->greaterThanOrEqualTo($windowStart) && $endAt->lessThanOrEqualTo($windowEnd);
            });

            if (! $matchingSchedule) {
                throw ValidationException::withMessages([
                    'scheduled_at' => ['The selected date and time is outside the doctor\'s available schedule.'],
                ]);
            }

            $matchingScheduleId = $matchingSchedule->id;
        }

        // 2. Prevent conflicting (overlapping) doctor appointments
        $dayStart = $scheduledAt->startOfDay()->subHours(12);
        $dayEnd = $scheduledAt->endOfDay()->addHours(12);

        $existingAppointments = Appointment::query()
            ->where('doctor_id', $doctorId)
            ->whereNotIn('status', Appointment::NON_BLOCKING_STATUSES)
            ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
            ->when($excludeAppointmentId !== null, fn ($query) => $query->where('id', '!=', $excludeAppointmentId))
            ->get(['id', 'scheduled_at', 'duration_minutes']);

        foreach ($existingAppointments as $existing) {
            $existingStart = CarbonImmutable::instance($existing->scheduled_at);
            $existingEnd = $existingStart->addMinutes((int) $existing->duration_minutes);

            if ($existingStart->lt($endAt) && $existingEnd->gt($scheduledAt)) {
                throw ValidationException::withMessages([
                    'scheduled_at' => [
                        sprintf(
                            'Schedule conflict: the doctor already has an appointment from %s to %s.',
                            $existingStart->format('g:i A'),
                            $existingEnd->format('g:i A'),
                        ),
                    ],
                ]);
            }
        }

        return $matchingScheduleId;
    }

    /**
     * Generate time slots for a doctor on a given date with availability flags.
     *
     * @return array<int, array{time: string, label: string, available: bool, reason: string|null}>
     */
    public function getAvailableSlots(
        int $doctorId,
        string $dateString,
        int $durationMinutes = 30,
        ?int $excludeAppointmentId = null,
    ): array {
        $targetDate = CarbonImmutable::parse($dateString);
        $dayOfWeek = $targetDate->dayOfWeek;

        $hasAnySchedules = DoctorSchedule::query()
            ->where('employee_id', $doctorId)
            ->where('is_active', true)
            ->exists();

        if ($hasAnySchedules) {
            $windows = DoctorSchedule::query()
                ->where('employee_id', $doctorId)
                ->where('is_active', true)
                ->where('day_of_week', $dayOfWeek)
                ->where(function ($query) use ($dateString): void {
                    $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $dateString);
                })
                ->where(function ($query) use ($dateString): void {
                    $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $dateString);
                })
                ->orderBy('starts_at')
                ->get()
                ->map(fn (DoctorSchedule $schedule): array => [
                    'start' => $schedule->starts_at,
                    'end' => $schedule->ends_at,
                    'step' => max(15, (int) $schedule->slot_minutes),
                ])
                ->all();
        } else {
            // Default clinic hours when doctor has no custom schedule rules
            $windows = [[
                'start' => '08:00:00',
                'end' => '17:00:00',
                'step' => 30,
            ]];
        }

        if ($windows === []) {
            return [];
        }

        $existingAppointments = Appointment::query()
            ->where('doctor_id', $doctorId)
            ->whereNotIn('status', Appointment::NON_BLOCKING_STATUSES)
            ->whereBetween('scheduled_at', [$targetDate->startOfDay(), $targetDate->endOfDay()])
            ->when($excludeAppointmentId !== null, fn ($query) => $query->where('id', '!=', $excludeAppointmentId))
            ->get(['id', 'scheduled_at', 'duration_minutes']);

        $now = CarbonImmutable::now();
        $slots = [];
        $seenTimes = [];

        foreach ($windows as $window) {
            $cursor = CarbonImmutable::parse($dateString.' '.$window['start']);
            $windowEnd = CarbonImmutable::parse($dateString.' '.$window['end']);
            $step = $window['step'];

            while ($cursor->addMinutes($durationMinutes)->lessThanOrEqualTo($windowEnd)) {
                $timeKey = $cursor->format('H:i');
                if (! isset($seenTimes[$timeKey])) {
                    $seenTimes[$timeKey] = true;
                    $slotEnd = $cursor->addMinutes($durationMinutes);

                    $isPast = $cursor->lessThanOrEqualTo($now);
                    $hasConflict = false;

                    foreach ($existingAppointments as $existing) {
                        $existingStart = CarbonImmutable::instance($existing->scheduled_at);
                        $existingEnd = $existingStart->addMinutes((int) $existing->duration_minutes);
                        if ($existingStart->lt($slotEnd) && $existingEnd->gt($cursor)) {
                            $hasConflict = true;
                            break;
                        }
                    }

                    $reason = null;
                    if ($isPast) {
                        $reason = 'Past time';
                    } elseif ($hasConflict) {
                        $reason = 'Booked';
                    }

                    $slots[] = [
                        'time' => $timeKey,
                        'label' => $cursor->format('g:i A'),
                        'available' => ! $isPast && ! $hasConflict,
                        'reason' => $reason,
                    ];
                }

                $cursor = $cursor->addMinutes($step);
            }
        }

        usort($slots, fn (array $a, array $b): int => strcmp($a['time'], $b['time']));

        return $slots;
    }

    /**
     * Record an audit/history entry for an appointment.
     */
    public function recordHistory(
        Appointment $appointment,
        ?int $userId,
        string $action,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $fromScheduledAt = null,
        ?string $toScheduledAt = null,
        ?string $notes = null,
    ): AppointmentHistory {
        return $appointment->histories()->create([
            'changed_by' => $userId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_scheduled_at' => $fromScheduledAt,
            'to_scheduled_at' => $toScheduledAt,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }
}

