<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    protected $fillable = ['medicine_id', 'medicine_batch_id', 'prescription_item_id', 'performed_by', 'type', 'quantity_change', 'unit_cost', 'reference_number', 'notes', 'occurred_at'];

    protected function casts(): array
    {
        return ['quantity_change' => 'decimal:3', 'unit_cost' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'medicine_batch_id');
    }

    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(PrescriptionItem::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
