<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Invoice extends Model
{
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_UNPAID,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'patient_id',
        'appointment_id',
        'consultation_id',
        'issued_by',
        'invoice_number',
        'issued_at',
        'due_at',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'due_at' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('paid_at')->orderByDesc('id');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function getPaidAmountAttribute(): float
    {
        if ($this->relationLoaded('payments')) {
            return round((float) $this->payments
                ->where('status', Payment::STATUS_COMPLETED)
                ->sum('amount'), 2);
        }

        return round((float) $this->payments()
            ->where('status', Payment::STATUS_COMPLETED)
            ->sum('amount'), 2);
    }

    public function getRemainingBalanceAttribute(): float
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return 0.0;
        }

        return round(max(0, (float) $this->total_amount - $this->paid_amount), 2);
    }

    public function recalculateTotals(): void
    {
        $subtotal = round((float) $this->items()->get()->sum(function (InvoiceItem $item): float {
            return round((float) $item->quantity * (float) $item->unit_price, 2);
        }), 2);

        $discount = round(max(0, (float) $this->discount_amount), 2);
        $tax = round(max(0, (float) $this->tax_amount), 2);
        $total = round(max(0, $subtotal - $discount + $tax), 2);

        $this->subtotal = $subtotal;
        $this->total_amount = $total;

        if ($this->status !== self::STATUS_CANCELLED) {
            $paid = $this->paid_amount;
            if ($total > 0 && $paid >= $total) {
                $this->status = self::STATUS_PAID;
            } elseif ($paid > 0) {
                $this->status = self::STATUS_PARTIALLY_PAID;
            } else {
                $this->status = self::STATUS_UNPAID;
            }
        }

        $this->save();
    }
}
