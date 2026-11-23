<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientPortalConsultationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consultation_number' => $this->consultation_number,
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'chief_complaint' => $this->chief_complaint,
            'symptoms' => $this->symptoms,
            'diagnosis' => $this->diagnosis,
            'treatment_plan' => $this->treatment_plan,
            'follow_up_date' => $this->follow_up_date?->toDateString(),
            'status' => $this->status,
            'released_to_patient_at' => $this->released_to_patient_at?->toISOString(),
            'doctor' => $this->whenLoaded('doctor', fn () => $this->doctor ? [
                'id' => $this->doctor->id,
                'full_name' => trim($this->doctor->first_name.' '.$this->doctor->last_name),
                'job_title' => $this->doctor->job_title,
                'department' => $this->doctor->relationLoaded('department') && $this->doctor->department ? [
                    'id' => $this->doctor->department->id,
                    'name' => $this->doctor->department->name,
                ] : null,
            ] : null),
            'appointment' => $this->whenLoaded('appointment', fn () => $this->appointment ? [
                'id' => $this->appointment->id,
                'appointment_number' => $this->appointment->appointment_number,
                'scheduled_at' => $this->appointment->scheduled_at?->toISOString(),
            ] : null),
            'vital_signs' => VitalSignResource::collection($this->whenLoaded('vitalSigns')),
            'prescriptions' => PrescriptionResource::collection($this->whenLoaded('prescriptions')),
        ];
    }
}
