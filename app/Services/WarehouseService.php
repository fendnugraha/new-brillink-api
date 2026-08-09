<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Warehouse;
use App\Models\WarehouseLease;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    /**
     * Update data Warehouse, Lease, dan Akun Kas Utama
     */
    public function updateWarehouse(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data) {
            // 1. Update Data Warehouse
            $warehouse->update([
                'name'              => strtoupper($data['name']),
                'address'           => $data['address'],
                'warehouse_zone_id' => $data['warehouse_zone_id'] ?? null,
                'opening_time'      => $data['opening_time'] ?? null,
                'status'            => $data['status'] ?? 1,
                'ownership_status'  => $data['ownership_status'] ?? 'owned',
            ]);

            // 2. Handling Data Lease / Sewa
            if ($data['ownership_status'] === 'leased') {
                // Tentukan status awal (jika tanggal akhir sudah terlewat, langsung set expired)
                $isExpired = !empty($data['lease_end_date']) && now()->parse($data['lease_end_date'])->endOfDay()->isPast();
                $leaseStatus = $isExpired ? 'expired' : 'active';

                $warehouse->lease()->updateOrCreate(
                    ['warehouse_id' => $warehouse->id],
                    [
                        'user_id'          => auth()->id(),
                        'status'           => $leaseStatus,
                        'lease_start_date' => $data['lease_start_date'] ?? null,
                        'lease_end_date'   => $data['lease_end_date'] ?? null,
                        'lease_type'       => $data['lease_type'] ?? null,
                        'rent_cost'        => $data['rent_cost'] ?? null,
                    ]
                );
            } else {
                // Hapus data lease jika berubah menjadi "Milik Sendiri"
                $warehouse->lease()->delete();
            }

            // 3. Update Akun Kas Utama
            $newAccountId = $data['chart_of_account_id'] ?? null;
            $currentPrimaryCash = ChartOfAccount::where('warehouse_id', $warehouse->id)
                ->where('is_primary_cash', true)
                ->first();

            if (!$currentPrimaryCash || $currentPrimaryCash->id != $newAccountId) {
                if ($currentPrimaryCash) {
                    $currentPrimaryCash->update(['is_primary_cash' => false]);
                }

                if ($newAccountId) {
                    ChartOfAccount::where('id', $newAccountId)->update([
                        'warehouse_id'    => $warehouse->id,
                        'is_primary_cash' => true,
                    ]);
                }
            }

            return $warehouse->load(['lease', 'primary_cash', 'zone']);
        });
    }

    /**
     * Otomatis update status sewa yang sudah lewati tanggal ke 'expired'
     */
    public function checkAndExpireLeases(): int
    {
        return WarehouseLease::where('status', 'active')
            ->whereDate('lease_end_date', '<', now()->toDateString())
            ->update(['status' => 'expired']);
    }
}
