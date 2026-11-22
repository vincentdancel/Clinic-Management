<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(Appointment::STATUSES)],
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}

