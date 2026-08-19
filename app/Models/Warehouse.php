<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Warehouse extends Model
{
    protected $guarded = ['id'];

    public function scopeWithinRadius($query, $latitude, $longitude, $radiusInMeters = 100)
    {
        // 6371000 adalah jari-jari bumi dalam satuan meter. 
        // Jika ingin menggunakan kilometer, ganti menjadi 6371.

        return $query->select('*')
            ->selectRaw(
                '(6371000 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance',
                [$latitude, $longitude, $latitude]
            )
            ->having('distance', '<=', $radiusInMeters)
            ->orderBy('distance', 'asc');
    }

    public function ChartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function primaryCash(): HasOne
    {
        return $this->hasOne(ChartOfAccount::class, 'warehouse_id')->where('is_primary_cash', 1);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'warehouse_id', 'id');
    }

    public function lease(): HasOne
    {
        return $this->hasOne(WarehouseLease::class);
    }

    public function journal(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    public function warehouse_expenses(): HasMany
    {
        return $this->hasMany(Journal::class)->where('trx_type', 'Pengeluaran');
    }

    public function transaction(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'warehouse_zone_id', 'id');
    }

    public static function toggleLockStatusById(int $id)
    {
        // 1. Cari data warehouse berdasarkan ID
        if ($id == 1) return false;
        $warehouse = self::findOrFail($id);

        // 2. Hitung status baru
        $newStatus = $warehouse->is_open === 1 ? 0 : ($warehouse->is_open === 0 ? 1 : $warehouse->is_open);

        // 3. Update is_opennya
        $warehouse->is_open = $newStatus;
        $warehouse->save();

        // 4. Kembalikan data warehouse yang sudah di-update
        return $warehouse;
    }

    public static function changeLockStatus(int $id, int $status)
    {
        $warehouse = self::findOrFail($id);
        if ($warehouse->id == 1) return;
        // Mengubah status: jika 1 jadi 0, jika 0 jadi 1, selain itu tetap
        $newStatus = $status;

        $warehouse->is_open = $newStatus;
        $warehouse->save(); // Lebih efisien untuk single model update

        return $warehouse;
    }
}
