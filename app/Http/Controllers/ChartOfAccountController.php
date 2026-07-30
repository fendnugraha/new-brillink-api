<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\AccountBalance;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChartOfAccountController extends Controller
{
    public string $startDate;
    public string $endDate;
    protected $appends = ['balance'];

    /**
     * Display a listing of the resource.
     */
    public function __construct()
    {
        $this->startDate = Carbon::now()->startOfMonth();
        $this->endDate = Carbon::now()->endOfMonth();
    }

    public function index(Request $request)
    {
        $chartOfAccounts = ChartOfAccount::with(['account', 'warehouse'])
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', '%' . $search . '%')
                    ->orWhere('code', 'like', '%' . $search . '%');
            })
            ->orderBy('code')->paginate(10)->onEachSide(0);
        return new AccountResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
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
        $chartOfAccount = new ChartOfAccount();
        $request->validate(
            [
                'category_id' => 'required',  // Make sure category_id is present
                'name' => 'required|string|max:255|unique:chart_of_accounts,name',
                'st_balance' => 'nullable|numeric',  // Allow st_balance to be nullable
            ],
            [
                'category_id.required' => 'Category account tidak boleh kosong.',
                'name.required' => 'Nama akun harus diisi.',
                'name.unique' => 'Nama akun sudah digunakan, silakan pilih nama lain.',
            ]
        );

        $chartOfAccount->create([
            'code' => $chartOfAccount->code($request->category_id),
            'name' => $request->name,
            'account_id' => $request->category_id,
            'group' => $request->account_group,
            'st_balance' => $request->st_balance ?? 0,
        ]);

        return response()->json([
            'message' => 'Chart of account created successfully',
            'chart_of_account' => $chartOfAccount
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $chartOfAccount = ChartOfAccount::with(['account', 'warehouse'])->find($id);
        return new AccountResource($chartOfAccount, true, "Successfully fetched chart of account");
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ChartOfAccount $chartOfAccount)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $chartOfAccount = ChartOfAccount::find($request->id);

        $request->validate(
            [
                'id' => 'required|exists:chart_of_accounts,id',
                'name' => 'required|string|max:255|unique:chart_of_accounts,name,' . $chartOfAccount->id,
                'st_balance' => 'nullable|numeric',
            ],
            [
                'name.required' => 'Nama akun harus diisi.',
                'name.unique' => 'Nama akun sudah digunakan, silakan pilih nama lain. ID:' . $chartOfAccount->id,
            ]
        );

        DB::beginTransaction();
        try {
            $chartOfAccount->update([
                'name' => $request->name,
                'group' => $request->account_group,
                'st_balance' => $request->st_balance ?? 0,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Account updated from ' . $chartOfAccount->name . ' to ' . $request->name,
                'chart_of_account' => $chartOfAccount
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update chart of account: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update chart of account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $chartOfAccount = ChartOfAccount::find($id);
        AccountBalance::where('chart_of_account_id', $chartOfAccount->id)->delete();
        if ($chartOfAccount->is_locked) {
            return response()->json([
                'message' => 'Chart of account is locked and cannot be deleted.',
            ], 403);
        }

        if (!$chartOfAccount) {
            return response()->json([
                'message' => 'Chart of account not found.',
            ], 404); // Return a 404 error if not found
        }

        try {
            $journalExists = Journal::where('debt_id', $chartOfAccount->code)
                ->orWhere('cred_id', $chartOfAccount->code)
                ->exists();

            if ($journalExists) {
                return response()->json([
                    'message' => 'Chart of account cannot be deleted because it is used in a journal entry.',
                ], 400);
            }
            // Deleting the Chart of Account
            $chartOfAccount->delete();

            // Return a success response
            return response()->json([
                'message' => 'Chart of account deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete chart of account. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getCashAndBankByWarehouse($warehouse)
    {
        $chartOfAccounts = ChartOfAccount::with('warehouse')->where('warehouse_id', $warehouse)->orderBy('code', 'asc')->get();
        return response()->json([
            'success' => true,
            'message' => 'Successfully fetched chart of accounts',
            'data' => $chartOfAccounts
        ]);
    }
    public function deleteAll(Request $request)
    {
        // Retrieve the records that are about to be deleted
        $accounts = ChartOfAccount::whereIn('id', $request->ids)->get();

        // Check if any of the records are locked
        $lockedAccounts = $accounts->filter(function ($account) {
            return $account->is_locked;
        });

        if ($lockedAccounts->isNotEmpty()) {
            return response()->json(
                [
                    'message' => 'Some chart of accounts are locked and cannot be deleted.',
                    'locked_accounts' => $lockedAccounts->pluck('id'), // Optionally return the ids of locked accounts
                ],
                403
            );
        }

        // Perform the deletion if no accounts are locked
        $deletedCount = ChartOfAccount::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'All chart of accounts deleted successfully',
            'deleted_count' => $deletedCount
        ], 200);
    }

    public function getCashAndBank()
    {
        $chartOfAccounts = ChartOfAccount::with('warehouse')->whereIn('account_id', [1, 2])->orderBy('code', 'asc')->get();
        return new AccountResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    public function profitLossReport()
    {
        $journal = new Journal();
        // $journal->profitLossCount('0000-00-00', $endDate);

        $transactions = $journal->with(['debt', 'cred'])
            ->selectRaw('debt_id, cred_id, SUM(amount) as total')
            ->whereBetween('date_issued', [$this->startDate, $this->endDate])
            ->groupBy('debt_id', 'cred_id')
            ->get();

        $chartOfAccounts = ChartOfAccount::with(['account'])->get();

        foreach ($chartOfAccounts as $value) {
            $debit = $transactions->where('debt_id', $value->code)->sum('total');
            $credit = $transactions->where('cred_id', $value->code)->sum('total');

            $value->balance = ($value->account->status == "D") ? ($value->st_balance + $debit - $credit) : ($value->st_balance + $credit - $debit);
        }

        $revenue = $chartOfAccounts->whereIn('account_id', \range(27, 30))->groupBy('account_id');
        $cost = $chartOfAccounts->whereIn('account_id', \range(31, 32))->groupBy('account_id');
        $expense = $chartOfAccounts->whereIn('account_id', \range(33, 45))->groupBy('account_id');

        $profitLoss = [
            'revenue' => [
                'total' => $revenue->flatten()->sum('balance'),
                'accounts' => $revenue->map(function ($r) {
                    return [
                        'name' => $r->first()->account->name,
                        'balance' => intval($r->sum('balance'))
                    ];
                })->toArray()
            ],
            'cost' => [
                'total' => $cost->flatten()->sum('balance'),
                'accounts' => $cost->map(function ($c) {
                    return [
                        'name' => $c->first()->account->name,
                        'balance' => intval($c->sum('balance'))
                    ];
                })->toArray()
            ],
            'expense' => [
                'total' => $expense->flatten()->sum('balance'),
                'accounts' => $expense->map(function ($e) {
                    return [
                        'name' => $e->first()->account->name,
                        'balance' => intval($e->sum('balance'))
                    ];
                })->toArray()
            ]
        ];

        return response()->json([
            'success' => true,
            'message' => 'Successfully fetched profit and loss',
            'data' => $profitLoss
        ]);
    }

    public function addCashAndBankToWarehouse($warehouse, $id)
    {
        $chartOfAccount = ChartOfAccount::find($id);

        if (!$warehouse || !$chartOfAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Warehouse or chart of account not found'
            ], 404);
        }
        $updateValue = $chartOfAccount->warehouse_id ? null : $warehouse;
        $chartOfAccount->update(['warehouse_id' => $updateValue]);

        $message = $chartOfAccount->warehouse_id ? 'Cash and bank account added to warehouse' : 'Cash and bank account removed from warehouse';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $chartOfAccount
        ]);
    }

    public function getExpenses()
    {
        $chartOfAccounts = ChartOfAccount::whereIn('account_id', range(33, 45))->get();
        return new AccountResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    public function getCashBankBalancex(int $warehouse, string $endDate)
    {
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();
        $previousDate = Carbon::parse($endDate)->subDays()->toDateString();

        $chartOfAccounts = ChartOfAccount::with(['account', 'limit'])->where('warehouse_id', $warehouse)->get();

        foreach ($chartOfAccounts as $chartOfAccount) {

            // Mengambil saldo awal dari properti model
            $initBalance = AccountBalance::where('chart_of_account_id', $chartOfAccount->id)->where('balance_date', $previousDate)->first()?->ending_balance ?? 0; // Tambahkan null coalescing operator untuk keamanan
            // Mengambil normal balance dari relasi 'account'
            $normalBalance = $chartOfAccount->account->status ?? ''; // Tambahkan null coalescing operator

            // Menghitung total debit langsung dari database
            $debit = Journal::where('debt_id', $chartOfAccount->id)
                ->whereBetween('date_issued', [$previousDate, $endDate])
                ->sum('amount');

            // Menghitung total credit langsung dari database
            $credit = Journal::where('cred_id', $chartOfAccount->id)
                ->whereBetween('date_issued', [$previousDate, $endDate])
                ->sum('amount');

            $chartOfAccount->balance = $initBalance + ($normalBalance === 'D' ? $debit - $credit : $credit - $debit);
        }


        return new AccountResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    public function getCashBankBalance(int $warehouse, string $endDate)
    {
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();
        $previousDate = Carbon::parse($endDate)->subDays()->toDateString();

        $chartOfAccounts = Journal::balancesByWarehouse($warehouse, $endDate);

        return new AccountResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    function countDaysInMonth($date)
    {
        $parsed = Carbon::parse($date);
        $selectedMonth = Carbon::create($parsed->year, $parsed->month, 1);
        $now = Carbon::now();

        return $selectedMonth->isSameMonth($now)
            ? $now->day
            : $selectedMonth->daysInMonth;
    }

    public function dailyDashboard(Request $request)
    {
        $warehouse = $request->query('warehouse', null);
        $startDate = $request->query('startDate') ? Carbon::parse($request->query('startDate'))->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $request->query('endDate') ? Carbon::parse($request->query('endDate'))->endOfDay() : Carbon::now()->endOfDay();

        $diffDays = Carbon::parse($endDate)->startOfDay()->diffInDays(Carbon::parse($startDate)->startOfDay());

        $warehouseBalance = Journal::balancesByWarehouse($warehouse, $endDate);

        $trxForSalesCount = Journal::selectRaw('
        trx_type,
        SUM(amount) as total_amount,
        SUM(fee_amount) as total_fee,
        COUNT(*) as total_count
    ')
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->when($warehouse !== 'all', fn($q) => $q->where('warehouse_id', $warehouse))
            ->groupBy('trx_type')
            ->get()
            ->keyBy('trx_type');

        $totalFee = Journal::selectRaw('
                SUM(fee_amount) as total_fee,
                SUM(CASE WHEN fee_amount > 0 THEN fee_amount ELSE 0 END) as total_fee_positive,
                SUM(CASE WHEN fee_amount < 0 THEN fee_amount ELSE 0 END) as total_fee_negative
            ')
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->when($warehouse !== 'all', fn($q) => $q->where('warehouse_id', $warehouse))
            ->first();

        $countTrxByType = Journal::whereBetween('date_issued', [$startDate, $endDate])
            ->when($warehouse !== 'all', fn($q) => $q->where('warehouse_id', $warehouse))
            ->whereIn('trx_type', ['Transfer Uang', 'Tarik Tunai', 'Deposit', 'Voucher & SP', 'Accessories', 'Bank Fee'])
            ->count();


        // $dailyReport = [
        //     'totalCash' => (int) $warehouseBalance['sumtotalCash'],
        //     'totalBank' => (int) $warehouseBalance['sumtotalBank'],
        //     'totalTransfer' => (int) ($trxForSalesCount['Transfer Uang']->total_amount ?? 0),
        //     'totalCashWithdrawal' => (int) ($trxForSalesCount['Tarik Tunai']->total_amount ?? 0),
        //     'totalCashDeposit' => (int) ($trxForSalesCount['Deposit']->total_amount ?? 0),
        //     'totalVoucher' => (int) ($trxForSalesCount['Voucher & SP']->total_amount ?? 0),
        //     'totalAccessories' => (int) ($trxForSalesCount['Accessories']->total_amount ?? 0),
        //     'totalExpense' => (int) ($trxForSalesCount['Pengeluaran']->total_fee ?? 0),
        //     'totalFee' => (int) ($totalFee->total_fee_positive ?? 0),
        //     'profit' => (int) ($totalFee->total_fee ?? 0),
        //     'salesCount' => $countTrxByType
        // ];
        $dailyReport = [
            'totalCash' => (int) $warehouseBalance['sumtotalCash'],
            'totalBank' => (int) $warehouseBalance['sumtotalBank'],
            'totalTransfer' => [
                'total' => (int) ($trxForSalesCount['Transfer Uang']->total_amount ?? 0),
                'count' => (int) ($trxForSalesCount['Transfer Uang']->total_count ?? 0)
            ],
            'totalCashWithdrawal' => [
                'total' => (int) ($trxForSalesCount['Tarik Tunai']->total_amount ?? 0),
                'count' => (int) ($trxForSalesCount['Tarik Tunai']->total_count ?? 0)
            ],
            'totalCashDeposit' => [
                'total' => (int) ($trxForSalesCount['Deposit']->total_amount ?? 0),
                'count' => (int) ($trxForSalesCount['Deposit']->total_count ?? 0)
            ],
            'totalVoucher' => [
                'total' => (int) ($trxForSalesCount['Voucher & SP']->total_amount ?? 0),
                'count' => (int) ($trxForSalesCount['Voucher & SP']->total_count ?? 0)
            ],
            'totalAccessories' => [
                'total' => (int) ($trxForSalesCount['Accessories']->total_amount ?? 0),
                'count' => (int) ($trxForSalesCount['Accessories']->total_count ?? 0)
            ],
            'totalExpense' => (int) ($trxForSalesCount['Pengeluaran']->total_fee ?? 0),
            'totalBankFee' => (int) ($trxForSalesCount['Bank Fee']->total_fee ?? 0),
            'totalFee' => (int) ($totalFee->total_fee_positive ?? 0),
            'totalCorrection' => (int) ($trxForSalesCount['Correction']->total_fee ?? 0),
            'profit' => (int) ($totalFee->total_fee ?? 0),
            'countDays' => $this->countDaysInMonth($startDate),
            'diffDays' => $diffDays,
            'averageProfit' => (int) (
                $diffDays == 0
                ? ($totalFee->total_fee_positive ?? 0)
                : (($totalFee->total_fee_positive ?? 0) / $this->countDaysInMonth($startDate))
            ),
            'salesCount' => $countTrxByType
        ];

        return new AccountResource($dailyReport, true, "Successfully fetched chart of accounts");
    }

    public function getAllAccounts()
    {
        $chartOfAccounts = ChartOfAccount::with(['account', 'warehouse:id,code,name'])->orderBy('code')->get();
        return new AccountResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    public function getAccountByAccountId(Request $request)
    {
        $accountIds = $request->input('account_ids', []);

        // Ensure it's an array
        if (!is_array($accountIds)) {
            $accountIds = explode(',', $accountIds); // Convert comma-separated values into an array
        }

        $chartOfAccounts = ChartOfAccount::with(['account'])
            ->whereIn('account_id', $accountIds)
            ->orderBy('code')
            ->get();

        return new AccountResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    public function updateAccountLimit(Request $request, $id)
    {
        $validated = $request->validate([
            'limit' => 'nullable|numeric',
            'diff'  => 'nullable|numeric'
        ]);

        $chartOfAccount = ChartOfAccount::findOrFail($id);

        $data = [];

        if ($request->has('limit')) {
            $data['limit_amount'] = $validated['limit'];
        }

        if ($request->has('diff')) {
            $data['diff_amount'] = $validated['diff'];
        }

        $chartOfAccount->limit()->updateOrCreate(
            ['chart_of_account_id' => $chartOfAccount->id],
            $data
        );

        return response()->json(['message' => 'Chart of account limit updated successfully.'], 200);
    }

    public function updateAccountLimitBatch(Request $request)
    {
        $validated = $request->validate([
            'accounts' => 'required|array',
            'accounts.*.id'   => 'required|exists:chart_of_accounts,id',
            'accounts.*.diff' => 'required|numeric',
        ]);

        Log::info($validated);

        DB::transaction(function () use ($validated) {

            foreach ($validated['accounts'] as $account) {

                $chartOfAccount = ChartOfAccount::find($account['id']);

                $limit = $chartOfAccount->limit()->firstOrCreate([
                    'chart_of_account_id' => $chartOfAccount->id
                ]);

                // REPLACE diff (bukan tambah)
                $limit->diff_amount = $account['diff'];
                $limit->save();
            }
        });

        return response()->json([
            'message' => 'Batch account diff updated successfully.'
        ]);
    }
}
