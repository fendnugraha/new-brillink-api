<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'warehouse_id',
    'user_id',
    'status',
    'lease_start_date',
    'lease_end_date',
    'lease_type',
    'rent_cost'
])]
class WarehouseLease extends Model
{
    /**
     * Casting tipe data otomatis dari Eloquent.
     */
    protected $casts = [
        'lease_start_date' => 'datetime:Y-m-d', // Menentukan format output JSON
        'lease_end_date'   => 'datetime:Y-m-d',
        'rent_cost'        => 'decimal:2',
        'status'           => 'string',
    ];

    /**
     * Relasi ke model Warehouse.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Relasi ke model User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
