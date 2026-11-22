<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consultation_id' => $this->consultation_id,
            'prescribed_at' => $this->prescribed_at?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
            'prescriber' => $this->whenLoaded('prescriber', fn () => $this->prescriber ? [
                'id' => $this->prescriber->id,
                'employee_number' => $this->prescriber->employee_number,
                'full_name' => trim($this->prescriber->first_name.' '.$this->prescriber->last_name),
                'job_title' => $this->prescriber->job_title,
            ] : null),
            'items' => PrescriptionItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

