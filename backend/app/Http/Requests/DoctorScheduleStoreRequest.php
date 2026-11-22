<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DoctorScheduleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'slot_minutes' => ['nullable', 'integer', 'min:10', 'max:120'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

