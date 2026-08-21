<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\Employee;
use App\Models\EmployeeWarning;
use App\Models\Payroll;
use App\Services\AttendanceRatingService;
use App\Services\EmployeeReceivableService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $month = $request->month ?? now()->month;
        $year = $request->year ?? now()->year;

        $date = Carbon::create($year, $month, 1);

        $lastMonth = $date->copy()->subMonth()->month;
        $lastYear = $date->copy()->subMonth()->year;

        $employees = Employee::with([
            'user.warehouse:id,name,warehouse_zone_id',
            'warningActive',
            'contact' => function ($query) {
                $query->select('id', 'name', 'user_id', 'photo')
                    ->with(['user', 'employee_receivables_sum']);
            },
            'attendances' => function ($q) use ($month, $year) {
                $q->whereMonth('date', $month)
                    ->whereYear('date', $year);
            },
            'attendancesLastMonth' => function ($q) use ($lastMonth, $lastYear) {
                $q->whereMonth('date', $lastMonth)
                    ->whereYear('date', $lastYear);
            },
            'attendances.warehouse:id,name,warehouse_zone_id',
            'salary_components'
        ])
            ->select('employees.*')
            // Join ke tabel contacts agar pengurutan nama dilakukan oleh Database
            ->join('contacts', 'employees.contact_id', '=', 'contacts.id')
            ->orderBy('contacts.name', 'asc')
            ->get();

        $ratingService = new AttendanceRatingService();

        foreach ($employees as $employee) {
            $employee->attendance_rating =
                $ratingService->calculateFromAttendances(
                    $employee->attendances
                );

            $employee->attendance_rating_last_month =
                $ratingService->calculateFromAttendances(
                    $employee->attendancesLastMonth
                );
        }

        return new AccountResource($employees, true, "Successfully fetched employees");
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
            'contact_id' => 'exists:contacts,id',
            'id_card_number' => 'string|max:20|unique:employees,id_card_number|nullable',
            'place_of_birth' => 'string|max:100|nullable',
            'birth_date' => 'date|nullable',
            'religion' => 'string|max:50',
            'marital_status' => 'string|in:single,married,divorced,widowed,other',
            'employment_type' => 'string|in:full_time,part_time,contract,internship',
            'base_salary' => 'numeric',
            'note' => 'string|max:255'
        ]);

        if ($request->employment_type === 'contract' && $request->has('contract_start')) {
            $request->validate([
                'contract_start' => 'date',
                'contract_duration' => 'integer|min:1'
            ]);
        }

        if (Employee::where('contact_id', $request->contact_id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Kontak sudah menjadi karyawan'], 400);
        }

        DB::beginTransaction();
        try {
            $employee = Employee::create([
                'contact_id' => $request->contact_id,
                'hire_date' => $request->hire_date ?? now(),
                'id_card_number' => $request->id_card_number,
                'place_of_birth' => $request->place_of_birth,
                'birth_date' => $request->birth_date,
                'religion' => $request->religion,
                'marital_status' => $request->marital_status,
                'employment_type' => $request->employment_type,
                'base_salary' => $request->base_salary,
                'contract_start' => $request->contract_start ?? null,
                'contract_end' => $request->employment_type === 'contract' ? Carbon::parse($request->contract_start)->addMonths($request->contract_duration ?? 12) : null,
                'note' => $request->note
            ]);

            DB::commit();

            return response()->json(['success' => true, 'data' => $employee, 'message' => 'Employee created successfully'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Employee $employee)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Employee $employee)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Employee $employee)
    {
        $request->validate([
            'contact_id' => 'exists:contacts,id',
            'hire_date' => 'date',
            'id_card_number' => 'nullable|string|max:20|unique:employees,id_card_number,' . $employee->id,
            'place_of_birth' => 'nullable|string|max:100',
            'birth_date' => 'nullable:date',
            'gender' => 'nullable|string|in:male,female',
            'religion' => 'nullable|string|max:50',
            'marital_status' => 'string|in:single,married,divorced,widowed,other',
            'employment_type' => 'string|in:full_time,part_time,contract,internship',
            'status' => 'string|in:active,inactive,resigned,retired,terminated',
            'base_salary' => 'numeric',
            'note' => 'string|max:255'
        ]);

        if ($request->employment_type === 'contract' && $request->has('contract_start')) {
            $request->validate([
                'contract_start' => 'date',
                'contract_end' => 'date|after:contract_start'
            ]);
        }

        DB::beginTransaction();
        try {
            $employee->update([
                'hire_date' => $request->hire_date ?? $employee->hire_date,
                'id_card_number' => $request->id_card_number ?? $employee->id_card_number,
                'place_of_birth' => $request->place_of_birth ?? $employee->place_of_birth,
                'birth_date' => $request->birth_date ?? $employee->birth_date,
                'gender' => $request->gender ?? $employee->gender,
                'religion' => $request->religion ?? $employee->religion,
                'marital_status' => $request->marital_status ?? $employee->marital_status,
                'employment_type' => $request->employment_type ?? $employee->employment_type,
                'base_salary' => $request->base_salary ?? $employee->base_salary,
                'contract_start' => $request->contract_start ?? $employee->contract_start,
                'contract_end' => $request->contract_end ?? $employee->contract_end,
                'status' => $request->status ?? $employee->status,
                'note' => $request->note ?? $employee->note
            ]);

            DB::commit();

            return response()->json(['success' => true, 'data' => $employee, 'message' => 'Employee updated successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Employee $employee)
    {
        //
    }

    public function storePayroll(Request $request, EmployeeReceivableService $service)
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

    public function getPayroll()
    {
        // $payroll = Payroll::query()
        //     ->selectRaw('
        //             payroll_date,
        //             employee_id,
        //             SUM(total_gross_pay) as total_gross_pay,
        //             SUM(total_commissions) as total_commissions,
        //             SUM(total_allowances) as total_allowances,
        //             SUM(total_deductions) as total_deductions,
        //             SUM(net_pay) as net_pay
        //         ')
        //     ->withSum([
        //         'items as total_savings' => function ($q) {
        //             $q->where('item_name', 'Simpanan Wajib');
        //         }
        //     ], 'amount')
        //     ->with('employee.contact')
        //     ->groupBy('payroll_date', 'employee_id')
        //     ->get();

        $payrollTotal = Payroll::leftJoin('payroll_items', function ($join) {
            $join->on('payrolls.id', '=', 'payroll_items.payroll_id')
                ->where('payroll_items.item_name', 'Simpanan Wajib');
        })
            ->selectRaw('
                payrolls.payroll_date,
                SUM(payrolls.total_gross_pay) as total_gross_pay,
                SUM(payrolls.total_commissions) as total_commissions,
                SUM(payrolls.total_allowances) as total_allowances,
                SUM(payrolls.total_deductions) as total_deductions,
                SUM(payrolls.net_pay) as net_pay,
                COALESCE(SUM(payroll_items.amount), 0) as total_savings
            ')
            ->groupBy('payrolls.payroll_date')
            ->get();


        $data = [
            'payroll' => [],
            'payrollTotal' => $payrollTotal
        ];

        return new AccountResource($data, true, "Successfully fetched payroll");
    }

    public function getPayrollByDate($date)
    {
        $date = Carbon::parse($date)->endOfMonth()->format('Y-m-d');
        $payroll = Payroll::with([
            'employee.contact',
            'items',
        ])
            ->where('payroll_date', $date)
            ->get();

        return response()->json(['success' => true, 'data' => $payroll]);
    }

    public function addWarning(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'level' => 'required|in:SP1,SP2,SP3',
            'reason' => 'required|string',
            'date_issued' => 'nullable|date',
        ]);

        $issuedDate = isset($validated['date_issued'])
            ? Carbon::parse($validated['date_issued'])
            : now();

        // clone agar tidak mengubah issued_date
        $expiredDate = match ($validated['level']) {
            'SP1' => $issuedDate->copy()->addMonths(3),
            'SP2' => $issuedDate->copy()->addMonths(3),
            'SP3' => $issuedDate->copy()->addMonths(3),
        };

        EmployeeWarning::create([
            'employee_id' => $validated['employee_id'],
            'level' => $validated['level'],
            'reason' => $validated['reason'],
            'issued_date' => $issuedDate,
            'expired_date' => $expiredDate,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Warning added successfully',
        ]);
    }

    public function attendanceRating(
        AttendanceRatingService $service,
        $employeeId,
        Request $request
    ) {
        return response()->json(
            $service->calculate(
                $employeeId,
                $request->month,
                $request->year
            )
        );
    }
}
