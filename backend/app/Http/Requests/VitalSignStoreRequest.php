<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VitalSignStoreRequest extends FormRequest
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
            'measured_at' => ['nullable', 'date'],
            'systolic_bp' => ['nullable', 'integer', 'min:40', 'max:300'],
            'diastolic_bp' => ['nullable', 'integer', 'min:20', 'max:200'],
            'heart_rate_bpm' => ['nullable', 'integer', 'min:20', 'max:250'],
            'respiratory_rate' => ['nullable', 'integer', 'min:5', 'max:80'],
            'temperature_c' => ['nullable', 'numeric', 'min:30', 'max:45'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.5', 'max:500'],
            'height_cm' => ['nullable', 'numeric', 'min:20', 'max:300'],
            'oxygen_saturation' => ['nullable', 'numeric', 'min:40', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $systolic = $this->input('systolic_bp');
                $diastolic = $this->input('diastolic_bp');

                if ($systolic !== null && $diastolic !== null && (int) $systolic <= (int) $diastolic) {
                    $validator->errors()->add('systolic_bp', 'Systolic blood pressure must be greater than diastolic blood pressure.');
                }

                $vitalFields = [
                    'systolic_bp',
                    'diastolic_bp',
                    'heart_rate_bpm',
                    'respiratory_rate',
                    'temperature_c',
                    'weight_kg',
                    'height_cm',
                    'oxygen_saturation',
                ];

                $hasAny = collect($vitalFields)->contains(fn (string $field): bool => $this->input($field) !== null && $this->input($field) !== '');
                if (! $hasAny) {
                    $validator->errors()->add('vital_signs', 'Provide at least one vital sign measurement.');
                }
            },
        ];
    }
}

