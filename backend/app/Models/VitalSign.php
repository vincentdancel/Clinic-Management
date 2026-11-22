<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VitalSign extends Model
{
    protected $fillable = ['consultation_id', 'recorded_by', 'measured_at', 'systolic_bp', 'diastolic_bp', 'heart_rate_bpm', 'respiratory_rate', 'temperature_c', 'weight_kg', 'height_cm', 'oxygen_saturation', 'notes'];

    protected function casts(): array
    {
        return ['measured_at' => 'datetime'];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
