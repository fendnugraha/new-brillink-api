<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['journal_id', 'source_account_id', 'destination_account_id', 'courier_id', 'received_by_id'])]
#[Appends(['invoice', 'amount', 'notes'])]
class Delivery extends Model
{
    use HasUuids;

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function getReferenceNoAttribute()
    {
        return $this->journal?->invoice;
    }

    public function getAmountAttribute()
    {
        return $this->journal?->amount;
    }

    public function getNotesAttribute()
    {
        return $this->journal?->notes;
    }

    // --- RELASI ---

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    // Akun Kas Asal (misal: Kas Besar HQ)
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'source_account_id');
    }

    // Akun Kas Tujuan (misal: Kas Brankas Toko Bandung)
    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'destination_account_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'courier_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'received_by_id');
    }
}
