<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consultation_number' => $this->consultation_number,
            'patient_id' => $this->patient_id,
            'doctor_id' => $this->doctor_id,
            'appointment_id' => $this->appointment_id,
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'chief_complaint' => $this->chief_complaint,
            'symptoms' => $this->symptoms,
            'diagnosis' => $this->diagnosis,
            'treatment_plan' => $this->treatment_plan,
            'clinical_notes' => $this->clinical_notes,
            'follow_up_date' => $this->follow_up_date?->toDateString(),
            'status' => $this->status,
            'is_released_to_patient' => (bool) $this->is_released_to_patient,
            'released_to_patient_at' => $this->released_to_patient_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? new PatientResource($this->patient) : null),
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
            'appointment' => $this->whenLoaded('appointment', fn () => $this->appointment ? [
                'id' => $this->appointment->id,
                'appointment_number' => $this->appointment->appointment_number,
                'scheduled_at' => $this->appointment->scheduled_at?->toISOString(),
                'status' => $this->appointment->status,
                'reason' => $this->appointment->reason,
            ] : null),
            'recorder' => $this->whenLoaded('recorder', fn () => $this->recorder ? [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ] : null),
            'vital_signs' => VitalSignResource::collection($this->whenLoaded('vitalSigns')),
            'latest_vital_sign' => $this->whenLoaded('vitalSigns', fn () => $this->vitalSigns->first() ? new VitalSignResource($this->vitalSigns->first()) : null),
            'prescriptions' => PrescriptionResource::collection($this->whenLoaded('prescriptions')),
        ];
    }
}

