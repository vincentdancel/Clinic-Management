<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...Appointment::STATUSES])],
            'doctor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}

