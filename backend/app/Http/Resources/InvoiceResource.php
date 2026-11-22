<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paidAmount = $this->paid_amount;
        $remainingBalance = $this->remaining_balance;

        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'consultation_id' => $this->consultation_id,
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'total_amount' => (float) $this->total_amount,
            'paid_amount' => $paidAmount,
            'remaining_balance' => $remainingBalance,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? [
                'id' => $this->patient->id,
                'patient_number' => $this->patient->patient_number,
                'first_name' => $this->patient->first_name,
                'last_name' => $this->patient->last_name,
                'full_name' => trim($this->patient->first_name.' '.$this->patient->last_name),
                'phone' => $this->patient->phone,
                'email' => $this->patient->email,
                'address' => $this->patient->address,
            ] : null),
            'appointment' => $this->whenLoaded('appointment', fn () => $this->appointment ? [
                'id' => $this->appointment->id,
                'appointment_number' => $this->appointment->appointment_number,
                'scheduled_at' => $this->appointment->scheduled_at?->toISOString(),
                'status' => $this->appointment->status,
            ] : null),
            'consultation' => $this->whenLoaded('consultation', fn () => $this->consultation ? [
                'id' => $this->consultation->id,
                'consultation_number' => $this->consultation->consultation_number,
                'started_at' => $this->consultation->started_at?->toISOString(),
                'chief_complaint' => $this->consultation->chief_complaint,
                'doctor' => $this->consultation->relationLoaded('doctor') && $this->consultation->doctor ? [
                    'id' => $this->consultation->doctor->id,
                    'full_name' => trim($this->consultation->doctor->first_name.' '.$this->consultation->doctor->last_name),
                    'job_title' => $this->consultation->doctor->job_title,
                ] : null,
            ] : null),
            'issuer' => $this->whenLoaded('issuer', fn () => $this->issuer ? [
                'id' => $this->issuer->id,
                'name' => $this->issuer->name,
            ] : null),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}

