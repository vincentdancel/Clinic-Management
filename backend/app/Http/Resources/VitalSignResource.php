<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VitalSignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consultation_id' => $this->consultation_id,
            'measured_at' => $this->measured_at?->toISOString(),
            'systolic_bp' => $this->systolic_bp,
            'diastolic_bp' => $this->diastolic_bp,
            'blood_pressure' => ($this->systolic_bp !== null && $this->diastolic_bp !== null)
                ? "{$this->systolic_bp}/{$this->diastolic_bp} mmHg"
                : null,
            'heart_rate_bpm' => $this->heart_rate_bpm,
            'respiratory_rate' => $this->respiratory_rate,
            'temperature_c' => $this->temperature_c,
            'weight_kg' => $this->weight_kg,
            'height_cm' => $this->height_cm,
            'bmi' => $this->bmi,
            'bmi_category' => $this->bmi_category,
            'oxygen_saturation' => $this->oxygen_saturation,
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder ? [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

