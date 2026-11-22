<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrescriptionStoreRequest extends FormRequest
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
            'prescribed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_id' => ['nullable', 'integer', Rule::exists('medicines', 'id')->whereNull('deleted_at')],
            'items.*.medicine_name' => ['required_without:items.*.medicine_id', 'nullable', 'string', 'max:160'],
            'items.*.dosage' => ['required', 'string', 'max:120'],
            'items.*.frequency' => ['required', 'string', 'max:120'],
            'items.*.duration' => ['nullable', 'string', 'max:80'],
            'items.*.duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'items.*.quantity_unit' => ['nullable', 'string', 'max:40'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

