<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:120'],
            'method' => ['nullable', Rule::in([...Payment::METHODS, 'all'])],
            'status' => ['nullable', Rule::in([Payment::STATUS_COMPLETED, Payment::STATUS_VOIDED, Payment::STATUS_REFUNDED, 'all'])],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}

