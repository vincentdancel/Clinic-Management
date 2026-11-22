<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('scheduled_at') && $this->filled('appointment_date') && $this->filled('appointment_time')) {
            $this->merge([
                'scheduled_at' => $this->input('appointment_date').' '.$this->input('appointment_time'),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'doctor_id' => ['required', 'integer', 'exists:employees,id'],
            'appointment_date' => ['nullable', 'date'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:240'],
            'reason' => ['required', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in([Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED])],
        ];
    }
}
