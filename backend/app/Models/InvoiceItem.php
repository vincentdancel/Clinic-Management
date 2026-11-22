<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    public const TYPE_CONSULTATION = 'consultation';
    public const TYPE_SERVICE = 'service';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_CONSULTATION,
        self::TYPE_SERVICE,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'invoice_id',
        'service_id',
        'prescription_item_id',
        'item_type',
        'description',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(PrescriptionItem::class);
    }
}
