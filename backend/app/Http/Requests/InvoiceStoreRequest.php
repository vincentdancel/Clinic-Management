<?php

namespace App\Http\Requests;

use App\Models\InvoiceItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceStoreRequest extends FormRequest
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
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id', Rule::unique('invoices', 'appointment_id')],
            'consultation_id' => ['nullable', 'integer', 'exists:consultations,id', Rule::unique('invoices', 'consultation_id')],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', Rule::in(InvoiceItem::TYPES)],
            'items.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'items.*.prescription_item_id' => ['nullable', 'integer', 'exists:prescription_items,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999.999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
        ];
    }
}

