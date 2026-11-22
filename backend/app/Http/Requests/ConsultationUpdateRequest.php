<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsultationUpdateRequest extends FormRequest
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
            'doctor_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('employees', 'id')
                    ->where('employee_category', 'doctor')
                    ->where('employment_status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'chief_complaint' => ['nullable', 'string', 'max:255'],
            'symptoms' => ['nullable', 'string', 'max:3000'],
            'diagnosis' => ['nullable', 'string', 'max:3000'],
            'treatment_plan' => ['nullable', 'string', 'max:5000'],
            'clinical_notes' => ['nullable', 'string', 'max:5000'],
            'follow_up_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(Consultation::STATUSES)],
        ];
    }
}

