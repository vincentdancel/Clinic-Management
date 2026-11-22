<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'appointment_id',
        'changed_by',
        'action',
        'from_status',
        'to_status',
        'from_scheduled_at',
        'to_scheduled_at',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_scheduled_at' => 'datetime',
            'to_scheduled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

