<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientMedicalHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'condition_name' => $this->condition_name,
            'details' => $this->details,
            'diagnosed_at' => $this->diagnosed_at?->toDateString(),
            'resolved_at' => $this->resolved_at?->toDateString(),
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
