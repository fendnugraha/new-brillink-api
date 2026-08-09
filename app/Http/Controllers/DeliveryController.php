<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $deliveries = Delivery::with([
            'journal:id,invoice,description,amount',

            'sourceAccount:id,name,warehouse_id',
            'sourceAccount.warehouse:id,name,latitude,longitude',
            'destinationAccount:id,name,warehouse_id',
            'destinationAccount.warehouse:id,name,latitude,longitude',

            'courier:id,contact_id',
            'courier.contact:id,name,phone,user_id',
            'courier.contact.user:id,name,email,latitude,longitude',

            'receiver:id,contact_id',
            'receiver.contact:id,name,phone,user_id',
            'receiver.contact.user:id,name,email,latitude,longitude',
        ])
            ->whereDate('created_at', today())
            ->latest()
            ->get();

        return new AccountResource($deliveries, true, "Successfully retrieved deliveries");
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Delivery $delivery)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Delivery $delivery)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Delivery $delivery)
    {
        $validated = $request->validate([
            'status'    => 'required|in:pending,in_transit,delivered,cancelled,picked_up',
            'amount'    => 'nullable|numeric',
            'note'      => 'nullable|string',
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        DB::transaction(function () use ($delivery, $validated, $request) {
            // 1. Update data delivery beserta koordinat lokasinya
            $delivery->update([
                'status'    => $validated['status'],
                'latitude'  => $validated['latitude'] ?? $delivery->latitude,
                'longitude' => $validated['longitude'] ?? $delivery->longitude,
            ]);

            // 2. Update jurnal jika ada
            if ($delivery->journal) {
                $delivery->journal->update([
                    'amount' => $validated['amount'] ?? $delivery->journal->amount,
                    'note'   => $validated['note'] ?? $delivery->journal->note,
                ]);
            }

            // 3. (Opsional) Update juga posisi koordinat kurir yang sedang login
            if ($request->user() && $validated['latitude']) {
                $request->user()->update([
                    'latitude'  => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Status dan lokasi berhasil diperbarui',
            'data'    => $delivery->fresh()
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Delivery $delivery)
    {
        //
    }

    public function process(Request $request, Delivery $delivery)
    {
        // Guard Clause: Hanya bisa diproses dari status pending
        if ($delivery->status !== 'pending') {
            return response()->json(['message' => 'Pengiriman sudah dalam proses atau selesai.'], 400);
        }

        $validated = $request->validate([
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        DB::transaction(function () use ($delivery, $validated, $request) {
            // Update status, waktu proses (picked_at), dan koordinat GPS
            $delivery->update([
                'status'    => 'in_transit',
                'picked_at' => now(), // Waktu otomatis tercatat saat tombol diklik
                'latitude'  => $validated['latitude'] ?? $delivery->latitude,
                'longitude' => $validated['longitude'] ?? $delivery->longitude,
            ]);

            // Update posisi koordinat Kurir di tabel users
            if ($request->user() && isset($validated['latitude'])) {
                $request->user()->update([
                    'latitude'  => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Pengiriman berhasil diproses',
            'data'    => $delivery->fresh()
        ]);
    }

    /**
     * 2. AKSI: Kurir Menyelesaikan Pengiriman (In Transit -> Delivered)
     */
    public function complete(Request $request, Delivery $delivery)
    {
        if ($delivery->status !== 'in_transit') {
            return response()->json(['message' => 'Pengiriman belum dalam status proses.'], 400);
        }

        $validated = $request->validate([
            'amount'    => 'nullable|numeric',
            'note'      => 'nullable|string',
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        DB::transaction(function () use ($delivery, $validated, $request) {
            // Update status, waktu selesai (delivered_at), dan koordinat lokasi akhir
            $delivery->update([
                'status'       => 'delivered',
                'delivered_at' => now(), // Waktu otomatis tercatat saat tombol diklik
                'latitude'     => $validated['latitude'] ?? $delivery->latitude,
                'longitude'    => $validated['longitude'] ?? $delivery->longitude,
            ]);

            // Update nominal & catatan di jurnal keuangan jika ada
            if ($delivery->journal) {
                $delivery->journal->update([
                    'amount' => $validated['amount'] ?? $delivery->journal->amount,
                    'note'   => $validated['note'] ?? $delivery->journal->note,
                ]);
            }

            // Update posisi koordinat Kurir di tabel users
            if ($request->user() && isset($validated['latitude'])) {
                $request->user()->update([
                    'latitude'  => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Pengiriman selesai',
            'data'    => $delivery->fresh()
        ]);
    }
}
