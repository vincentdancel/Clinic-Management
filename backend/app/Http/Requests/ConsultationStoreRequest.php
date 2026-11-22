<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsultationStoreRequest extends FormRequest
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
            'patient_id' => [
                'required_without:appointment_id',
                'nullable',
                'integer',
                Rule::exists('patients', 'id')->whereNull('deleted_at'),
            ],
            'doctor_id' => [
                'required_without:appointment_id',
                'nullable',
                'integer',
                Rule::exists('employees', 'id')
                    ->where('employee_category', 'doctor')
                    ->where('employment_status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'appointment_id' => [
                'nullable',
                'integer',
                Rule::exists('appointments', 'id'),
                Rule::unique('consultations', 'appointment_id'),
            ],
            'started_at' => ['nullable', 'date'],
            'chief_complaint' => ['nullable', 'string', 'max:255'],
            'symptoms' => ['nullable', 'string', 'max:3000'],
            'diagnosis' => ['nullable', 'string', 'max:3000'],
            'treatment_plan' => ['nullable', 'string', 'max:5000'],
            'clinical_notes' => ['nullable', 'string', 'max:5000'],
            'follow_up_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(Consultation::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient_id.exists' => 'The selected patient must be a registered active patient.',
            'doctor_id.exists' => 'The selected doctor must be an active registered doctor.',
            'appointment_id.unique' => 'A consultation already exists for this appointment.',
        ];
    }
}

