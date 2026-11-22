<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AppointmentRescheduleRequest extends FormRequest
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
            'doctor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'appointment_date' => ['nullable', 'date'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:240'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}

