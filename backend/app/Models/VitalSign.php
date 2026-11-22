<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VitalSign extends Model
{
    protected $fillable = [
        'consultation_id',
        'recorded_by',
        'measured_at',
        'systolic_bp',
        'diastolic_bp',
        'heart_rate_bpm',
        'respiratory_rate',
        'temperature_c',
        'weight_kg',
        'height_cm',
        'oxygen_saturation',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'systolic_bp' => 'integer',
            'diastolic_bp' => 'integer',
            'heart_rate_bpm' => 'integer',
            'respiratory_rate' => 'integer',
            'temperature_c' => 'float',
            'weight_kg' => 'float',
            'height_cm' => 'float',
            'oxygen_saturation' => 'float',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getBmiAttribute(): ?float
    {
        if (! $this->weight_kg || ! $this->height_cm || $this->weight_kg <= 0 || $this->height_cm <= 0) {
            return null;
        }

        $heightMeters = $this->height_cm / 100;

        return round($this->weight_kg / ($heightMeters * $heightMeters), 1);
    }

    public function getBmiCategoryAttribute(): ?string
    {
        $bmi = $this->bmi;
        if ($bmi === null) {
            return null;
        }

        return match (true) {
            $bmi < 18.5 => 'Underweight',
            $bmi < 25.0 => 'Normal',
            $bmi < 30.0 => 'Overweight',
            default => 'Obese',
        };
    }
}
