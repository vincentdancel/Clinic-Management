<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'employee_number' => $this->employee_number, 'first_name' => $this->first_name, 'last_name' => $this->last_name, 'full_name' => trim($this->first_name.' '.$this->last_name),
            'gender' => $this->gender, 'date_of_birth' => $this->date_of_birth?->toDateString(), 'phone' => $this->phone, 'email' => $this->email, 'address' => $this->address,
            'employee_category' => $this->employee_category, 'job_title' => $this->job_title, 'hire_date' => $this->hire_date?->toDateString(), 'employment_status' => $this->employment_status,
            'department' => $this->whenLoaded('department', fn () => ['id' => $this->department?->id, 'name' => $this->department?->name, 'code' => $this->department?->code]),
            'specializations' => $this->whenLoaded('doctorSpecializations', fn () => $this->doctorSpecializations->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'is_primary' => (bool) $item->pivot->is_primary])->values()),
            'account' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'email' => $this->user->email, 'is_active' => $this->user->is_active, 'role' => $this->user->role ? ['id' => $this->user->role->id, 'name' => $this->user->role->name, 'slug' => $this->user->role->slug] : null] : null),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
