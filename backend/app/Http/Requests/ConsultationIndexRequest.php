<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsultationIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in([...Consultation::STATUSES, 'all'])],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'doctor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}

