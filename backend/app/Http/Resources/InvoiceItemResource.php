<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quantity = (float) $this->quantity;
        $unitPrice = (float) $this->unit_price;
        $lineTotal = (float) $this->line_total > 0 || $unitPrice == 0.0
            ? (float) $this->line_total
            : round($quantity * $unitPrice, 2);

        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'service_id' => $this->service_id,
            'prescription_item_id' => $this->prescription_item_id,
            'item_type' => $this->item_type ?? 'service',
            'description' => $this->description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'service' => $this->whenLoaded('service', fn () => $this->service ? [
                'id' => $this->service->id,
                'code' => $this->service->code,
                'name' => $this->service->name,
                'price' => (float) $this->service->price,
            ] : null),
        ];
    }
}

