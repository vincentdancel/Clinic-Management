<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'receipt_number' => $this->receipt_number,
            'idempotency_key' => $this->idempotency_key,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'reference_number' => $this->reference_number,
            'paid_at' => $this->paid_at?->toISOString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'receiver' => $this->whenLoaded('receiver', fn () => $this->receiver ? [
                'id' => $this->receiver->id,
                'name' => $this->receiver->name,
            ] : null),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'total_amount' => (float) $this->invoice->total_amount,
                'paid_amount' => $this->invoice->paid_amount,
                'remaining_balance' => $this->invoice->remaining_balance,
                'status' => $this->invoice->status,
                'issued_at' => $this->invoice->issued_at?->toDateString(),
                'patient' => $this->invoice->relationLoaded('patient') && $this->invoice->patient ? [
                    'id' => $this->invoice->patient->id,
                    'patient_number' => $this->invoice->patient->patient_number,
                    'first_name' => $this->invoice->patient->first_name,
                    'last_name' => $this->invoice->patient->last_name,
                    'full_name' => trim($this->invoice->patient->first_name.' '.$this->invoice->patient->last_name),
                    'phone' => $this->invoice->patient->phone,
                    'email' => $this->invoice->patient->email,
                ] : null,
                'items' => $this->invoice->relationLoaded('items')
                    ? InvoiceItemResource::collection($this->invoice->items)
                    : [],
            ] : null),
        ];
    }
}

