<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $endsAt = $this->scheduled_at?->copy()->addMinutes((int) $this->duration_minutes);

        return [
            'id' => $this->id,
            'appointment_number' => $this->appointment_number,
            'patient_id' => $this->patient_id,
            'doctor_id' => $this->doctor_id,
            'doctor_schedule_id' => $this->doctor_schedule_id,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'appointment_date' => $this->scheduled_at?->toDateString(),
            'appointment_time' => $this->scheduled_at?->format('H:i'),
            'ends_at' => $endsAt?->toISOString(),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status,
            'reason' => $this->reason,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? [
                'id' => $this->patient->id,
                'patient_number' => $this->patient->patient_number,
                'first_name' => $this->patient->first_name,
                'last_name' => $this->patient->last_name,
                'full_name' => trim($this->patient->first_name.' '.$this->patient->last_name),
                'phone' => $this->patient->phone,
                'email' => $this->patient->email,
            ] : null),
            'doctor' => $this->whenLoaded('doctor', fn () => $this->doctor ? [
                'id' => $this->doctor->id,
                'employee_number' => $this->doctor->employee_number,
                'first_name' => $this->doctor->first_name,
                'last_name' => $this->doctor->last_name,
                'full_name' => trim($this->doctor->first_name.' '.$this->doctor->last_name),
                'job_title' => $this->doctor->job_title,
                'department' => $this->doctor->relationLoaded('department') && $this->doctor->department ? [
                    'id' => $this->doctor->department->id,
                    'name' => $this->doctor->department->name,
                ] : null,
                'specializations' => $this->doctor->relationLoaded('doctorSpecializations')
                    ? $this->doctor->doctorSpecializations->map(fn ($item) => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'is_primary' => (bool) $item->pivot->is_primary,
                    ])->values()
                    : [],
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'histories' => AppointmentHistoryResource::collection($this->whenLoaded('histories')),
        ];
    }
}

