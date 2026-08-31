<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Services\EmployeeReceivableService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function store(Request $request, EmployeeReceivableService $service)
    {
        $payrollDate = Carbon::create(
            $request->year,
            $request->month,
            1
        )->endOfMonth();
        Log::info($payrollDate);

        DB::beginTransaction();

        try {
            foreach ($request->employees as $item) {

                // ❌ Cegah payroll double di bulan yang sama
                $exists = Payroll::where('employee_id', $item['employee_id'])
                    ->whereMonth('payroll_date', $request->month)
                    ->whereYear('payroll_date', $request->year)
                    ->exists();

                if ($exists) {
                    throw new \Exception("Payroll sudah ada untuk salah satu karyawan");
                }

                $basicSalary = $item['basic_salary'] ?? 0;
                $commission  = $item['commission'] ?? 0;
                $overtime = $item['overtime'] ?? 0;
                $employeeReceivable = $item['employee_receivable'] ?? 0;
                $installmentReceivable = $item['installment_receivable'] ?? 0;

                $totalBonus = collect($item['bonuses'] ?? [])
                    ->sum('amount') + $overtime;

                $totalDeduction = collect($item['deductions'] ?? [])
                    ->sum('amount') + $employeeReceivable + $installmentReceivable;

                $grossPay = $basicSalary + $commission + $totalBonus;
                $netPay   = $grossPay - $totalDeduction;

                $payroll = Payroll::create([
                    'employee_id'        => $item['employee_id'],
                    'payroll_date'       => Carbon::create(
                        $request->year,
                        $request->month,
                        1
                    )->endOfMonth(),
                    'total_gross_pay'    => $basicSalary,
                    'total_commissions'   => $commission,
                    'total_allowances'   => $totalBonus,
                    'total_deductions'   => $totalDeduction,
                    'net_pay'            => $netPay,
                    'type'               => $request->type || "monthly"
                ]);

                // 💾 Simpan bonus
                foreach ($item['bonuses'] ?? [] as $bonus) {
                    $payroll->items()->create([
                        'type' => 'allowance',
                        'item_name' => $bonus['name'],
                        'amount' => $bonus['amount'],
                    ]);
                }

                // 💾 Simpan deduction
                foreach ($item['deductions'] ?? [] as $deduction) {
                    $payroll->items()->create([
                        'type' => 'deduction',
                        'item_name' => $deduction['name'],
                        'amount' => $deduction['amount'],
                    ]);
                }

                if ($overtime > 0) {
                    $payroll->items()->create([
                        'type' => 'allowance',
                        'item_name' => 'Lembur',
                        'amount' => $overtime,
                    ]);
                }

                if ($employeeReceivable > 0) {
                    $payroll->items()->create([
                        'type' => 'deduction',
                        'item_name' => 'Potong Kasbon',
                        'amount' => $employeeReceivable,
                    ]);

                    $service->pay([
                        'contact_id' => $item['contact_id'],
                        'amount' => $employeeReceivable,
                        'date_issued' => $payrollDate,
                        'chart_of_account_id' => 1,
                        'notes' => 'Potongan kasbon bulan ' . $payrollDate->format('F Y'),
                    ]);
                }

                if ($installmentReceivable > 0) {
                    $payroll->items()->create([
                        'type' => 'deduction',
                        'item_name' => 'Potong Cicilan',
                        'amount' => $installmentReceivable,
                    ]);

                    $service->pay([
                        'contact_id' => $item['contact_id'],
                        'amount' => $installmentReceivable,
                        'date_issued' => $payrollDate,
                        'chart_of_account_id' => 1,
                        'notes' => 'Potongan kasbon bulan ' . $payrollDate->format('F Y'),
                        'finance_type' => 'InstallmentReceivable',
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payroll berhasil disimpan',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
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
