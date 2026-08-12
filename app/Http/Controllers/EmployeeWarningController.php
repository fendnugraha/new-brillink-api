<?php

namespace App\Http\Controllers;

use App\Models\EmployeeWarning;
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
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'level'       => 'required|in:SP1,SP2,SP3',
            'issued_date' => 'required|date',
            'reason'      => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            // letter_number & expired_date akan terisi otomatis di background!
            $sp = EmployeeWarning::create([
                'employee_id' => $request->employee_id,
                'issued_by'   => auth()->id(),
                'level'       => $request->level,
                'issued_date' => $request->issued_date,
                'reason'      => $request->reason,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Surat Peringatan berhasil diterbitkan',
                'data'    => $sp // $sp->letter_number akan berisi "001/HRD-SP1/VIII/2026"
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error($e->getMessage());
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
