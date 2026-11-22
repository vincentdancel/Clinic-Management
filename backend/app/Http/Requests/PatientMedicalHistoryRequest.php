<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PatientMedicalHistoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'condition_name' => ['required', 'string', 'max:160'],
            'details' => ['nullable', 'string', 'max:5000'],
            'diagnosed_at' => ['nullable', 'date', 'before_or_equal:today'],
            'resolved_at' => ['nullable', 'date', 'after_or_equal:diagnosed_at', 'before_or_equal:today'],
        ];
    }
}
