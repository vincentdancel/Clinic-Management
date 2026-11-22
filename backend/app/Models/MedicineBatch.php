<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicineBatch extends Model
{
    protected $fillable = ['medicine_id', 'batch_number', 'received_at', 'expires_at', 'quantity_received', 'quantity_remaining', 'unit_cost'];

    protected function casts(): array
    {
        return ['received_at' => 'date', 'expires_at' => 'date', 'quantity_received' => 'decimal:3', 'quantity_remaining' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
