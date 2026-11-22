<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_number' => $this->patient_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'age' => $this->date_of_birth?->age,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'status' => $this->trashed() ? 'archived' : 'active',
            'created_at' => $this->created_at?->toISOString(),
            'archived_at' => $this->deleted_at?->toISOString(),
            'allergies' => PatientAllergyResource::collection($this->whenLoaded('allergies')),
            'medical_histories' => PatientMedicalHistoryResource::collection($this->whenLoaded('medicalHistories')),
        ];
    }
}
