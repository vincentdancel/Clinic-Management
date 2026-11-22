<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prescription_id' => $this->prescription_id,
            'medicine_id' => $this->medicine_id,
            'medicine_name' => $this->medicine_name ?? $this->medicine?->name,
            'dosage' => $this->dosage,
            'frequency' => $this->frequency,
            'duration' => $this->duration ?? ($this->duration_days ? "{$this->duration_days} days" : null),
            'duration_days' => $this->duration_days,
            'quantity' => $this->quantity,
            'quantity_unit' => $this->quantity_unit ?? 'units',
            'instructions' => $this->instructions,
        ];
    }
}

