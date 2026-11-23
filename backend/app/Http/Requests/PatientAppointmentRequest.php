<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PatientAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'integer', 'exists:employees,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
