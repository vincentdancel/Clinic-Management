<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DoctorSchedule extends Model
{
    protected $fillable = ['employee_id', 'day_of_week', 'starts_at', 'ends_at', 'slot_minutes', 'valid_from', 'valid_until', 'is_active'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
