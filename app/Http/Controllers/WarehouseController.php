<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Jobs\ProcessWarehouseLock;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\Warehouse;
use App\Services\WarehouseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarehouseController extends Controller
{
    protected WarehouseService $warehouseService;

    public function __construct(WarehouseService $warehouseService)
    {
        $this->warehouseService = $warehouseService;
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $warehouses = Warehouse::with(['primaryCash', 'contact:id,name', 'zone'])
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', '%' . $search . '%');
            })->paginate(20)->onEachSide(0);
        return new AccountResource($warehouses, true, "Successfully fetched warehouses");
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|size:3|unique:warehouses,code',
            'name' => 'required|min:3|max:90',
            'address' => 'required|min:3|max:160',
            'acc_code' => 'required',
        ]);

        DB::beginTransaction();
        try {
            // Create and save the warehouse
            $warehouse = Warehouse::create([
                'code' => strtoupper($request->code),
                'name' => strtoupper($request->name),
                'address' => $request->address,
                'chart_of_account_id' => $request->acc_code
            ]);

            // Update the related ChartOfAccount with the warehouse ID
            ChartOfAccount::where('id', $request->acc_code)->update(['warehouse_id' => $warehouse->id]);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Warehouse created successfully',
                'data' => $warehouse
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            // Flash an error message
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Warehouse creation failed',
                'data' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        $warehouse = Warehouse::with('ChartOfAccount')->find($id);

        if (!$warehouse) {
            return response()->json([
                'success' => false,
                'message' => 'Warehouse not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $warehouse
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Warehouse $warehouse)
    {
        // 1. Validasi Input
        $request->validate([
            'name'                => 'required|string|min:3|max:90',
            'address'             => 'required|string|min:3|max:160',
            'chart_of_account_id' => 'nullable|exists:chart_of_accounts,id',
            'warehouse_zone_id'   => 'nullable|exists:warehouse_zones,id',
            'opening_time'        => 'nullable|string',
            'status'              => 'nullable|in:1,0', // Status warehouse (1 / 0)
            'ownership_status'    => 'required|in:owned,leased',

            // Validasi Kondisional Sewa
            'lease_start_date'    => 'required_if:ownership_status,leased|nullable|date',
            'lease_end_date'      => 'required_if:ownership_status,leased|nullable|date|after:lease_start_date',
            'lease_type'          => 'required_if:ownership_status,leased|nullable|string',
            'rent_cost'           => 'required_if:ownership_status,leased|nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            // 2. Update Warehouse
            $warehouse->update([
                'name'              => strtoupper($request->name),
                'address'           => $request->address,
                'warehouse_zone_id' => $request->warehouse_zone_id ?? null,
                'opening_time'      => $request->opening_time,
                'status'            => $request->status ?? 1,
                'ownership_status'  => $request->ownership_status ?? 'owned',
            ]);

            // 3. Update / Create Lease Data
            if ($request->ownership_status === 'leased') {
                // Tentukan status kontrak berdasarkan tanggal selesai
                $leaseStatus = 'active';
                if ($request->lease_end_date && now()->parse($request->lease_end_date)->isPast()) {
                    $leaseStatus = 'expired';
                }

                $warehouse->lease()->updateOrCreate(
                    ['warehouse_id' => $warehouse->id],
                    [
                        'user_id'          => auth()->id(),
                        'status'           => $leaseStatus, // Menggunakan string: 'active', 'expired', 'terminated'
                        'lease_start_date' => $request->lease_start_date,
                        'lease_end_date'   => $request->lease_end_date,
                        'lease_type'       => $request->lease_type,
                        'rent_cost'        => $request->rent_cost,
                    ]
                );
            } else {
                // Hapus data lease jika status berubah menjadi 'owned'
                $warehouse->lease()->delete();
            }

            // 4. Update Akun Kas Utama
            $newAccountId = $request->chart_of_account_id;

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

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Warehouse updated successfully',
                'data'    => $warehouse->load(['lease', 'primaryCash', 'zone']),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to update warehouse ID {$warehouse->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Warehouse update failed',
                'error'   => config('app.debug') ? $e->getMessage() : 'Server Error',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Warehouse $warehouse)
    {
        if ($warehouse->is_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Warehouse is locked and cannot be deleted.'
            ], 403);
        }

        $journalExists = Journal::where('warehouse_id', $warehouse->id)->exists();
        if ($journalExists || $warehouse->id == 1) {
            return response()->json([
                'success' => false,
                'message' => 'Warehouse cannot be deleted because it has related transactions.'
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Delete the warehouse
            $warehouse->delete();

            // Update the related ChartOfAccount with the warehouse ID
            ChartOfAccount::where('id', $warehouse->chart_of_account_id)->update(['warehouse_id' => null]);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Warehouse deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            // Flash an error message
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Warehouse deletion failed',
                'data' => $e->getMessage()
            ], 500);
        }
    }

    public function getAllWarehouses()
    {
        $warehouses = Warehouse::with(['primaryCash', 'zone', 'lease'])->orderBy('name', 'asc')->get();
        return response()->json([
            'success' => true,
            'data' => $warehouses,
            'message' => 'Successfully fetched warehouses'
        ], 200);
    }

    public function updateWarehouseLocation(Request $request, Warehouse $warehouse)
    {
        $request->validate([
            'latitude' => 'required',
            'longitude' => 'required',
        ]);

        Log::info($request->all());

        try {
            $warehouse->update([
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'address' => $request->address
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Warehouse location updated successfully',
                'data' => $warehouse
            ], 200);
        } catch (\Exception $e) {
            // Flash an error message
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Warehouse location update failed',
                'data' => $e->getMessage()
            ], 500);
        }
    }

    public function resetLocation(Warehouse $warehouse)
    {
        try {
            $warehouse->update([
                'latitude' => null,
                'longitude' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Warehouse location reset successfully',
                'data' => $warehouse
            ], 200);
        } catch (\Exception $e) {
            // Flash an error message
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Warehouse location reset failed',
                'data' => $e->getMessage()
            ], 500);
        }
    }

    public function changeLockStatus(Warehouse $warehouse, Request $request)
    {
        if ($warehouse->id == 1) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot lock default warehouse'
            ], 400);
        }

        $newStatus = $request->status;

        // Ambil nilai delay (misal kita sepakati satuannya adalah MENIT, jadi inputnya angka 5)
        $delayInMinutes = $request->delay ?? 0;

        if ($delayInMinutes > 0) {
            // 🔥 JIKA ADA DELAY: Lempar ke sistem antrean server, tunda selama 5 menit
            ProcessWarehouseLock::dispatch($warehouse->id, $newStatus)->delay(now()->addMinutes($delayInMinutes));

            return response()->json([
                'success' => true,
                'message' => 'Proses penguncian dijadwalkan otomatis dalam ' . $delayInMinutes . ' menit.',
                'delayed' => true
            ], 200);
        }

        // JIKA TIDAK ADA DELAY (Normal): Langsung eksekusi kunci detik itu juga
        $warehouse->is_open = $newStatus;
        $warehouse->save();

        return response()->json([
            'success' => true,
            'message' => 'Warehouse ' . ($newStatus === 1 ? 'unlocked' : 'locked'),
            'data' => $warehouse,
            'delayed' => false
        ], 200);
    }

    public function checkWarehouseStatus(Warehouse $warehouse)
    {
        return response()->json([
            'success' => true,
            'data' => $warehouse
        ], 200);
    }

    public function getNearestWarehouse(Request $request)
    {
        $request->validate([
            'latitude'  => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        $maxRadius = 50; // Radius toleransi dalam meter

        $warehouse = Warehouse::where('id', '!=', 1)->withinRadius($request->latitude, $request->longitude, $maxRadius)->first();

        if (!$warehouse) {
            return response()->json([
                'found' => false,
                'message' => 'Anda berada di luar radius semua warehouse.'
            ]);
        }

        return response()->json([
            'found' => true,
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'opening_time' => $warehouse->opening_time,
                'distance' => round($warehouse->distance, 1) // Mengembalikan jarak dalam meter
            ]
        ]);
    }

    public function updateOpenHours(Request $request)
    {
        $request->validate([
            'warehouseIds' => 'required|array',
            'warehouseIds.*' => 'exists:warehouses,id',
            'opening_time' => 'required|date_format:H:i',
        ]);

        Warehouse::whereIn('id', $request->warehouseIds)->update($request->only(['opening_time', 'closing_time']));

        return response()->json([
            'success' => true,
            'message' => 'Open hours updated successfully',
        ], 200);
    }
}
