<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PayrollController extends Controller
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Payroll $payroll)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Payroll $payroll)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Payroll $payroll)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Payroll $payroll)
    {
        //
    }

    public function monthlyPayrollSum(Request $request, $date = null)
    {
        // 1. Parsing tanggal dengan penanganan error jika format tidak valid
        try {
            $selectedDate = $date ? Carbon::parse($date) : Carbon::now();
        } catch (\Exception $e) {
            $selectedDate = Carbon::now();
        }

        // 2. Gunakan COALESCE agar SUM yang bernilai null otomatis menjadi 0
        $monthlyPayroll = Payroll::selectRaw('
            COALESCE(SUM(total_gross_pay + total_commissions + total_allowances - total_deductions), 0) as total_salary,
            COUNT(*) as employee_count
        ')
            ->whereYear('payroll_date', $selectedDate->year)
            ->whereMonth('payroll_date', $selectedDate->month)
            ->first();

        // 3. Cast nilai ke tipe data angka yang pasti
        return response()->json([
            'success' => true,
            'date' => $selectedDate->format('Y-m'),
            'total_salary' => (float) ($monthlyPayroll->total_salary ?? 0)
        ]);
    }
}
