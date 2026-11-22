<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'from_scheduled_at' => $this->from_scheduled_at?->toISOString(),
            'to_scheduled_at' => $this->to_scheduled_at?->toISOString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null),
        ];
    }
}

