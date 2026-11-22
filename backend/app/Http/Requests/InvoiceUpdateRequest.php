<?php

namespace App\Http\Requests;

use App\Models\InvoiceItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceUpdateRequest extends FormRequest
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
            'issued_at' => ['sometimes', 'date'],
            'due_at' => ['nullable', 'date'],
            'discount_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999.99'],
            'tax_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_type' => ['required_with:items', Rule::in(InvoiceItem::TYPES)],
            'items.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'items.*.prescription_item_id' => ['nullable', 'integer', 'exists:prescription_items,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0', 'max:99999.999'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0', 'max:9999999.99'],
        ];
    }
}

