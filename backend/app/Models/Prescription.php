<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prescription extends Model
{
    protected $fillable = ['consultation_id', 'prescribed_by', 'prescribed_at', 'notes', 'status'];

    protected function casts(): array
    {
        return ['prescribed_at' => 'date'];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'prescribed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }
}
