<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrescriptionItem extends Model
{
    protected $fillable = [
        'prescription_id',
        'medicine_id',
        'medicine_name',
        'dosage',
        'frequency',
        'duration',
        'duration_days',
        'quantity',
        'quantity_unit',
        'instructions',
    ];

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'quantity' => 'float',
        ];
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
