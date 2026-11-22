<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorScheduleResource extends JsonResource
{
    private const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'day_of_week' => $this->day_of_week,
            'day_name' => self::DAY_NAMES[$this->day_of_week] ?? 'Unknown',
            'starts_at' => substr((string) $this->starts_at, 0, 5),
            'ends_at' => substr((string) $this->ends_at, 0, 5),
            'slot_minutes' => $this->slot_minutes,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'is_active' => $this->is_active,
        ];
    }
}

