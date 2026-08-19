<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeWarning;
use App\Notifications\SendPushNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeWarningController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'level'       => 'required|in:SP1,SP2,SP3',
            'issued_date' => 'required|date',
            'reason'      => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            // letter_number & expired_date akan terisi otomatis via Model Observer / Event Listener
            $sp = EmployeeWarning::create([
                'employee_id' => $validated['employee_id'],
                'issued_by'   => auth()->id(),
                'level'       => $validated['level'],
                'issued_date' => $validated['issued_date'],
                'reason'      => $validated['reason'],
            ]);

            DB::commit();

            // Send Push Notification (Setelah DB Commit)
            try {
                $emp = Employee::with('user')->find($validated['employee_id']);
                $empUser = $emp?->user;

                if ($empUser?->fcm_token) {
                    $empUser->notify(new SendPushNotification(
                        'Surat Peringatan',
                        'Kamu memiliki surat peringatan level ' . $sp->level . ' No: ' . $sp->letter_number,
                        [
                            'employee_warning_id' => (string) $sp->id,
                            'type' => 'employee_warnings',
                        ]
                    ));
                } else {
                    Log::warning("FCM Warning Skipped: Employee ID {$validated['employee_id']} has no valid user/FCM token.");
                }
            } catch (\Exception $e) {
                // Error FCM ditangkap terpisah agar respon HTTP tetap sukses 200
                Log::error('FCM Employee Warning Notification Error: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Surat Peringatan berhasil diterbitkan',
                'data'    => $sp
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Store Employee Warning Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal diterbitkan',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(EmployeeWarning $employeeWarning)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EmployeeWarning $employeeWarning)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, EmployeeWarning $employeeWarning)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EmployeeWarning $employeeWarning)
    {
        //
    }
}
