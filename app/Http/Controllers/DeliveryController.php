<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\Delivery;
use App\Models\Employee;
use App\Notifications\SendPushNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'status'     => 'sometimes|required|in:pending,in_transit,delivered,cancelled,picked_up',
            'courier_id' => 'nullable|exists:employees,id',
            'priority'   => 'sometimes|in:low,medium,high,urgent',
            'amount'     => 'nullable|numeric|gt:0',
            'note'       => 'nullable|string',
            'latitude'   => 'nullable|numeric|between:-90,90',
            'longitude'  => 'nullable|numeric|between:-180,180',
        ]);

        $previousCourierId = $delivery->courier_id;

        DB::transaction(function () use ($delivery, $validated, $request) {
            $deliveryUpdates = [];

            if ($request->has('status')) {
                $deliveryUpdates['status'] = $validated['status'];
                if ($validated['status'] === 'delivered' && ! $delivery->received_at) {
                    $deliveryUpdates['received_at'] = now();
                }
            }

            if ($request->has('courier_id')) {
                $deliveryUpdates['courier_id'] = $validated['courier_id'] ?: null;
            }

            if ($request->has('priority')) {
                $deliveryUpdates['priority'] = $validated['priority'];
            }

            if (! empty($deliveryUpdates)) {
                $delivery->update($deliveryUpdates);
            }

            // 2. Update jurnal jika ada perubahan nominal atau catatan
            if ($delivery->journal && ($request->filled('amount') || $request->has('note'))) {
                $journalUpdates = [];
                if ($request->filled('amount')) {
                    $journalUpdates['amount'] = $validated['amount'];
                }
                if ($request->has('note') && $validated['note'] !== null) {
                    $journalUpdates['description'] = $validated['note'];
                }
                if (! empty($journalUpdates)) {
                    $delivery->journal->update($journalUpdates);
                }
            }

            // 3. Update posisi koordinat user/kurir jika dikirim
            if ($request->user() && ! empty($validated['latitude']) && ! empty($validated['longitude'])) {
                $request->user()->update([
                    'latitude'  => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                ]);
            }
        });

        // Kirim notifikasi ke kurir baru jika kurir diganti
        if ($request->has('courier_id') && ! empty($validated['courier_id']) && (int) $validated['courier_id'] !== (int) $previousCourierId) {
            try {
                $employee = Employee::with('contact.user')->find($validated['courier_id']);
                $courierUser = $employee?->contact?->user;
                if ($courierUser?->fcm_token) {
                    $invoice = $delivery->journal?->invoice ?? '-';
                    $courierUser->notify(new SendPushNotification(
                        'Tugas Pengiriman Dialihkan',
                        "Kamu ditugaskan untuk pengiriman uang: {$invoice}",
                        [
                            'journal_id' => (string) $delivery->journal_id,
                            'type' => 'delivery_tasks',
                        ]
                    ));
                }
            } catch (\Exception $e) {
                Log::error('FCM Reassign Courier Error: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Data pengiriman berhasil diperbarui',
            'data'    => $delivery->fresh([
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
            ]),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Delivery $delivery)
    {
        DB::transaction(function () use ($delivery) {
            $journal = $delivery->journal;
            $delivery->delete();
            if ($journal) {
                $journal->delete();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Pengiriman berhasil dihapus',
        ]);
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
