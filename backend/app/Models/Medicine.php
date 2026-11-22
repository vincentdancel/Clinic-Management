<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Medicine extends Model
{
    use SoftDeletes;

    protected $fillable = ['medicine_category_id', 'sku', 'name', 'generic_name', 'brand', 'dosage_form', 'unit', 'reorder_level', 'unit_cost', 'selling_price', 'manufacturer', 'requires_prescription', 'is_active'];

    protected function casts(): array
    {
        return ['reorder_level' => 'decimal:3', 'unit_cost' => 'decimal:2', 'selling_price' => 'decimal:2', 'requires_prescription' => 'boolean', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MedicineCategory::class, 'medicine_category_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class);
    }

    public function prescriptionItems(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
