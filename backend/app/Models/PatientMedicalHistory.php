<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMedicalHistory extends Model
{
    protected $fillable = ['patient_id', 'recorded_by', 'condition_name', 'details', 'diagnosed_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['diagnosed_at' => 'date', 'resolved_at' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
