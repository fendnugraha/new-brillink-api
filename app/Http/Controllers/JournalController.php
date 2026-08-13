<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\AccountBalance;
use App\Models\AccountLimit;
use App\Models\ChartOfAccount;
use App\Models\Employee;
use App\Models\Journal;
use App\Models\LogActivity;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\Transaction;
// use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\SendPushNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class JournalController extends Controller
{
    public String $startDate;
    public String $endDate;
    /**
     * Display a listing of the resource.
     */

    public function __construct()
    {
        $this->startDate = Carbon::now()->startOfDay();
        $this->endDate = Carbon::now()->endOfDay();
    }

    public function index()
    {
        $journals = Journal::with(['debt', 'cred'])->orderBy('created_at', 'desc')->paginate(10, ['*'], 'journalPage')->onEachSide(0)->withQueryString();
        return new AccountResource($journals, true, "Successfully fetched journals");
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
    public function show(string $id)
    {
        $journal = Journal::with(['debt', 'cred'])->find($id);
        return new AccountResource($journal, true, "Successfully fetched journal");
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'cred_id' => 'required|exists:chart_of_accounts,id',
            'debt_id' => 'required|exists:chart_of_accounts,id',
            'amount' => 'required|numeric|min:1',
            'fee_amount' => 'required|numeric|min:0',
            'description' => 'max:255',
        ]);

        $journal = Journal::findOrFail($id); // Better to fail gracefully
        $log = new LogActivity();
        $isAmountChanged = $journal->amount != $request->amount;
        $isFeeAmountChanged = $journal->fee_amount != $request->fee_amount;

        if (auth()->user()->role !== 'Super Admin') {
            if (Carbon::parse($journal->date_issued)->lt(Carbon::now()->startOfDay())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengubah journal. Tanggal journal tidak boleh lebih kecil dari tanggal sekarang.'
                ], 400);
            }
        }

        DB::beginTransaction();
        try {
            $oldAmount = $journal->amount;
            $oldFeeAmount = $journal->fee_amount;

            $journal->update($request->all());

            $descriptionParts = [];
            if ($isAmountChanged) {
                $oldAmountFormatted = number_format($oldAmount, 0, ',', '.');
                $newAmountFormatted = number_format($request->amount, 0, ',', '.');
                $descriptionParts[] = "Amount changed from Rp $oldAmountFormatted to Rp $newAmountFormatted.";
            }
            if ($isFeeAmountChanged) {
                $oldFeeFormatted = number_format($oldFeeAmount, 0, ',', '.');
                $newFeeFormatted = number_format($request->fee_amount, 0, ',', '.');
                $descriptionParts[] = "Fee amount changed from Rp $oldFeeFormatted to Rp $newFeeFormatted.";
            }


            if ($isAmountChanged || $isFeeAmountChanged) {
                $log->create([
                    'user_id' => auth()->id(),
                    'warehouse_id' => $journal->warehouse_id,
                    'activity' => 'Updated Journal',
                    'description' => 'ID: ' . $journal->id . '. ' . implode(' ', $descriptionParts),
                ]);
            }

            if ($journal->date_issued) {
                try {
                    $dateIssued = Carbon::parse($journal->date_issued);

                    if ($dateIssued->lt(Carbon::now()->startOfDay())) {
                        $this->_updateBalancesDirectly($dateIssued);
                    }
                } catch (\Exception $e) {
                    Log::warning("Invalid date_issued format: {$journal->date_issued}");
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update journal',
            ]);
        }

        return new AccountResource($journal, true, "Successfully updated journal");
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Journal $journal)
    {
        $warehouseStatusCheck = Warehouse::find($journal->warehouse_id);
        if ($warehouseStatusCheck->is_open === 0 && auth()->user()->role !== 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus journal. Gudang sedang di tutup.'
            ], 400);
        }
        $transactionsExist = $journal->transaction()->exists();
        // if ($transactionsExist) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Journal cannot be deleted because it has transactions'
        //     ]);
        // }
        $issued = Carbon::parse($journal->date_issued);

        if (!$issued->isToday() && auth()->user()->role !== 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus journal. Tanggal journal tidak boleh lebih kecil dari tanggal sekarang.'
            ], 400);
        }


        $log = new LogActivity();
        DB::beginTransaction();
        try {
            $journal->delete();
            if ($transactionsExist) {
                $journal->transaction()->delete();
            }

            $log->create([
                'user_id' => auth()->user()->id,
                'warehouse_id' => $journal->warehouse_id,
                'activity' => 'Deleted Journal',
                'description' => 'ID: ' . $journal->id . ' (' . $journal->description . ' from ' . $journal->cred->name . ' to ' . $journal->debt->name . ' with amount: ' . number_format($journal->amount, 0, ',', '.') . ' and fee amount: ' . number_format($journal->fee_amount, 0, ',', '.') . ')',
            ]);

            if ($journal->date_issued) {
                try {
                    $dateIssued = Carbon::parse($journal->date_issued);

                    if ($dateIssued->lt(Carbon::now()->startOfDay())) {
                        $this->_updateBalancesDirectly($dateIssued);
                    }
                } catch (\Exception $e) {
                    Log::warning("Invalid date_issued format: {$journal->date_issued}");
                }
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Journal deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            // Flash an error message
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete journal'
            ]);
        }
    }

    public function createTransfer(Request $request)
    {
        // 1. Validasi Input (ditambahkan validasi date_issued & cegah akun sama)
        $request->validate([
            'debt_id'     => 'required|exists:chart_of_accounts,id',
            'cred_id'     => 'required|exists:chart_of_accounts,id|different:debt_id',
            'amount'      => 'required|numeric|min:1',
            'trx_type'    => 'required|string',
            'fee_amount'  => 'required|numeric|min:0',
            'custName'    => 'required|regex:/^[a-zA-Z0-9\s]+$/|min:3|max:255',
            'date_issued' => 'nullable|date',
        ], [
            'debt_id.required'  => 'Akun debet harus diisi.',
            'cred_id.required'  => 'Akun kredit harus diisi.',
            'cred_id.different' => 'Akun kredit tidak boleh sama dengan akun debet.',
            'custName.required' => 'Customer name harus diisi.',
            'custName.regex'    => 'Customer name tidak valid.',
            'date_issued.date'  => 'Format tanggal penerbitan tidak valid.',
        ]);

        $user = auth()->user();

        // 2. Cek Status Gudang
        $warehouseStatusCheck = Warehouse::find($user->warehouse_id);
        if ($warehouseStatusCheck && $warehouseStatusCheck->is_open === 0 && $user->role !== 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat transaksi. Gudang sedang ditutup.' // Fix typo "menghapus" -> "membuat"
            ], 400);
        }

        // 3. Parse Tanggal & Cek Validasi Backdate
        $dateIssued = $request->date_issued ? Carbon::parse($request->date_issued) : now();

        if ($dateIssued->lt(Carbon::now()->startOfDay()) && $user->role !== 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat membuat jurnal sebelum tanggal sekarang.'
            ], 422); // Diubah dari 500 ke 422
        }

        // 4. Cek Aturan Fee Bank
        if ($request->trx_type === 'Bank Fee' && (float) $request->fee_amount !== (float) $request->amount) {
            return response()->json([
                'success' => false,
                'message' => 'Fee Bank tidak boleh berbeda dengan jumlah transfer.'
            ], 422); // Diubah dari 500 ke 422
        }

        // 5. Format Deskripsi Transaction
        $custNameUpper = strtoupper($request->custName);
        $description   = $request->description
            ? $request->description . ' - ' . $custNameUpper
            : $request->trx_type . ' - ' . $custNameUpper;

        DB::beginTransaction();
        try {
            $journal = Journal::create([
                'invoice'      => Journal::invoice_journal(),
                'date_issued'  => $dateIssued,
                'debt_id'      => $request->debt_id,
                'cred_id'      => $request->cred_id,
                'amount'       => $request->amount,
                'fee_amount'   => $request->fee_amount,
                'trx_type'     => $request->trx_type,
                'description'  => $description,
                'user_id'      => $user->id,
                'warehouse_id' => $user->warehouse_id
            ]);

            // Jika transaksi dilakukan secara backdate (oleh Super Admin), update saldo terkait secara langsung
            if ($dateIssued->lt(Carbon::now()->startOfDay())) {
                $this->_updateBalancesDirectly($dateIssued);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Journal created successfully',
                'journal' => $journal->load('debt', 'cred')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create transfer journal: {$e->getMessage()}", [
                'user_id' => $user->id,
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create journal: ' . $e->getMessage()
            ], 500);
        }
    }

    public function createVoucher(Request $request)
    {
        $request->validate([
            'qty' => 'required|numeric|min:1',
            'price' => 'required|numeric|min:1',
            'product_id' => 'required',
        ], [
            'qty.required' => 'Jumlah voucher harus diisi.',
            'qty.numeric' => 'Jumlah voucher harus berupa angka.',
            'qty.min' => 'Jumlah voucher harus lebih besar dari 0.',
            'price.required' => 'Harga voucher harus diisi.',
            'price.numeric' => 'Harga voucher harus berupa angka.',
            'price.min' => 'Harga voucher harus lebih besar dari 0.',
            'product_id.required' => 'Pilih produk terlebih dahulu.',
        ]);

        $warehouseStatusCheck = Warehouse::find(auth()->user()->warehouse_id);
        if ($warehouseStatusCheck->is_open === 0 && auth()->user()->role !== 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus journal. Gudang sedang di tutup.'
            ], 400);
        }

        $journal = new Journal();
        // $modal = $this->modal * $this->qty;
        $price = $request->price * $request->qty;
        $cost = Product::find($request->product_id)->cost;
        $modal = $cost * $request->qty;

        if ($cost > $price) {
            return response()->json([
                'success' => false,
                'message' => 'Harga jualan tidak boleh lebih besar dari harga modal.'
            ], 500);
        }

        $description = $request->description ?? "Penjualan Voucher & SP";
        $fee = $price - $modal;
        $invoice = $journal->invoice_journal();

        DB::beginTransaction();
        try {
            $journal->create([
                'invoice' => $invoice,  // Menggunakan metode statis untuk invoice
                'date_issued' => $request->date_issued ?? now(),
                'debt_id' => 9,
                'cred_id' => 9,
                'amount' => $modal,
                'fee_amount' => $fee,
                'trx_type' => 'Voucher & SP',
                'description' => $description,
                'user_id' => auth()->user()->id,
                'warehouse_id' => auth()->user()->warehouse_id
            ]);

            $sale = new Transaction([
                'date_issued' => $request->date_issued ?? now(),
                'invoice' => $invoice,
                'product_id' => $request->product_id,
                'quantity' => -$request->qty,
                'price' => $request->price,
                'cost' => $cost,
                'transaction_type' => 'Sales',
                'contact_id' => 1,
                'warehouse_id' => auth()->user()->warehouse_id,
                'user_id' => auth()->user()->id
            ]);
            $sale->save();

            $sold = Product::find($request->product_id)->sold + $request->qty;
            Product::find($request->product_id)->update(['sold' => $sold]);

            DB::commit();

            return response()->json([
                'message' => 'Penjualan voucher berhasil, invoice: ' . $invoice,
                'journal' => $journal
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create journal'
            ], 500);
        }
    }

    public function createDeposit(Request $request)
    {
        $journal = new Journal();
        $request->validate([
            'cost' => 'required|numeric',
            'price' => 'required|numeric|gte:cost',
            // 'price' => 'required|numeric|min:' . $request->cost,
        ], [
            'cost.required' => 'Biaya deposit harus diisi.',
            'cost.numeric' => 'Biaya deposit harus berupa angka.',
            'price.required' => 'Harga deposit harus diisi.',
            'price.numeric' => 'Harga deposit harus berupa angka.',
            'price.gte' => 'Harga jual harus lebih besar atau sama dengan harga modal.',
        ]);

        $warehouseStatusCheck = Warehouse::find(auth()->user()->warehouse_id);
        if ($warehouseStatusCheck->is_open === 0 && auth()->user()->role !== 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus journal. Gudang sedang di tutup.'
            ], 400);
        }

        if ($request->cost > $request->price) {
            return response()->json([
                'success' => false,
                'message' => 'Harga jualan tidak boleh lebih besar dari harga modal.'
            ], 500);
        }

        // $modal = $request->modal * $request->qty;
        $price = $request->price;
        $cost = $request->cost;

        $description = $request->description ?? "Penjualan Pulsa Dll";
        $fee = $price - $cost;
        $invoice = Journal::invoice_journal();

        DB::beginTransaction();
        try {
            $journal->create([
                'invoice' => $invoice,  // Menggunakan metode statis untuk invoice
                'date_issued' => $request->date_issued ?? now(),
                'debt_id' => 9,
                'cred_id' => 9,
                'amount' => $cost,
                'fee_amount' => $fee,
                'trx_type' => 'Deposit',
                'description' => $description,
                'user_id' => auth()->user()->id,
                'warehouse_id' => auth()->user()->warehouse_id
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Penjualan deposit berhasil, invoice: ' . $invoice,
                'journal' => $journal
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create journal'
            ], 500);
        }
    }

    public function createMutation(Request $request)
    {
        // 1. Validasi Input Terpusat (Bebas dari Try-Catch)
        $request->validate([
            'date_issued'  => 'nullable|date',
            'debt_id'      => 'required|exists:chart_of_accounts,id',
            'cred_id'      => 'required|exists:chart_of_accounts,id|different:debt_id',
            'amount'       => 'required|numeric|min:0',
            'trx_type'     => 'required|string',
            'admin_fee'    => 'nullable|numeric|min:0',
            'fee_amount'   => 'nullable|numeric',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'description'  => [
                'nullable',
                'string',
                'max:255',
                // Otomatis required jika trx_type = Pengeluaran ATAU admin_fee > 0
                Rule::requiredIf(fn() => $request->trx_type === 'Pengeluaran' || (float) $request->admin_fee > 0)
            ]
        ], [
            'admin_fee.numeric'    => 'Biaya admin harus berupa angka.',
            'debt_id.required'     => 'Akun debet harus diisi.',
            'cred_id.required'     => 'Akun kredit harus diisi.',
            'cred_id.different'    => 'Akun debet dan kredit tidak boleh sama.',
            'amount.required'      => 'Jumlah harus diisi.',
            'amount.numeric'       => 'Jumlah harus berupa angka.',
            'amount.min'           => 'Jumlah minimal adalah 0.',
            'description.required' => 'Deskripsi wajib diisi untuk transaksi pengeluaran / biaya admin.'
        ]);

        $user = auth()->user();

        // 2. Custom Business Validations
        if ($request->trx_type === 'Mutasi Kas' && (float) $request->amount === 0.0) {
            return response()->json([
                'success' => false,
                'message' => 'Jumlah mutasi kas tidak boleh 0.'
            ], 422);
        }

        // Single Parsing untuk Tanggal
        $dateIssued = $request->date_issued ? Carbon::parse($request->date_issued) : now();

        if ($dateIssued->lt(Carbon::now()->startOfDay()) && $user->role !== 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat membuat jurnal sebelum tanggal sekarang.'
            ], 422); // Diubah dari 500 ke 422
        }

        // 3. Persiapan Data Tambahan
        $description = $request->description ?? 'Mutasi Kas';

        // Ambil akun kas HQ dengan aman
        $hqWarehouse = Warehouse::find(1);
        $hqCashAccount = $hqWarehouse?->chart_of_account_id;

        $debt = ChartOfAccount::find($request->debt_id);
        $cred = ChartOfAccount::find($request->cred_id);

        // Cek status konfirmasi
        $confirmation = ($cred && $cred->account_id == 1 && $cred->warehouse_id == 1)
            ? ($request->confirmation ?? 0)
            : 1;

        DB::beginTransaction();
        try {
            // 4. Jurnal Utama
            $journal = Journal::create([
                'invoice'      => Journal::invoice_journal(),
                'date_issued'  => $dateIssued,
                'debt_id'      => $request->debt_id,
                'cred_id'      => $request->cred_id,
                'amount'       => $request->amount,
                'is_confirmed' => $request->is_confirmed ?? 0,
                'status'       => $confirmation,
                'fee_amount'   => $request->fee_amount ?? 0,
                'trx_type'     => $request->trx_type,
                'description'  => $description,
                'user_id'      => $user->id,
                'warehouse_id' => $request->warehouse_id ?? $user->warehouse_id
            ]);

            // 5. Jurnal Biaya Admin (Jika ada)
            $adminFee = (float) ($request->admin_fee ?? 0);
            if ($adminFee > 0) {
                Journal::create([
                    'invoice'      => Journal::invoice_journal(),
                    'date_issued'  => $dateIssued,
                    'debt_id'      => $hqCashAccount,
                    'cred_id'      => $request->cred_id,
                    'amount'       => $adminFee,
                    'fee_amount'   => -$adminFee,
                    'trx_type'     => 'Pengeluaran',
                    'description'  => $description,
                    'user_id'      => $user->id,
                    'warehouse_id' => 1
                ]);
            }

            // 6. Cash Flow (Pengeluaran / Biaya Admin)
            if ($request->trx_type === 'Pengeluaran' || $adminFee > 0) {
                $cashFlowAmount = $adminFee > 0 ? ($adminFee * -1) : ((float) $request->fee_amount * -1);

                $journal->cashFlow()->create([
                    'date_issued'  => $dateIssued,
                    'amount'       => $cashFlowAmount,
                    'type'         => 'expense',
                    'description'  => $description,
                    'category'     => $debt?->name ?? 'Pengeluaran',
                    'is_corporate' => 0,
                    'user_id'      => $user->id
                ]);
            }

            // 7. Update Saldo Langsung untuk Backdate Transaction
            if ($dateIssued->lt(Carbon::now()->startOfDay())) {
                $this->_updateBalancesDirectly($dateIssued);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mutasi Kas berhasil dibuat.',
                'journal' => $journal->load(['debt.warehouse:id,name', 'cred'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create mutation journal: {$e->getMessage()}", [
                'user_id' => $user->id,
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat mutasi kas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function createMutationMultiple(Request $request)
    {
        $request->validate([
            'cred_id' => 'required|exists:chart_of_accounts,id',
            'amount' => 'required|numeric|min:0',
            'account_ids' => 'required|array',
            'account_ids.*' => 'exists:chart_of_accounts,id',
        ], [
            'admin_fee.numeric' => 'Biaya admin harus berupa angka.',
            'cred_id.required' => 'Akun kredit harus diisi.',
            'account_ids.required' => 'Akun debet harus diisi.',
            'account_ids.*.exists' => 'Akun debet tidak valid.',
        ]);

        $cred = ChartOfAccount::find($request->cred_id);
        $confirmation = $cred->account_id == 1 && $cred->warehouse_id == 1 ? $request->confirmation : 1;
        DB::beginTransaction();
        try {
            foreach ($request->account_ids as $account_id) {

                $journal = Journal::create([
                    'invoice' => Journal::invoice_journal(),  // Menggunakan metode statis untuk invoice
                    'date_issued' => $request->date_issued ?? now(),
                    'debt_id' => $account_id,
                    'cred_id' => $request->cred_id,
                    'amount' => $request->amount,
                    'is_confirmed' => 1,
                    'status' => $confirmation,
                    'fee_amount' => 0,
                    'trx_type' => 'Mutasi Kas',
                    'description' => $request->description ?? 'Penambahan Kas',
                    'user_id' => auth()->user()->id,
                    'warehouse_id' => 1
                ]);
            }

            if ($request->date_issued) {
                try {
                    $dateIssued = Carbon::parse($request->date_issued);

                    if ($dateIssued->lt(Carbon::now()->startOfDay())) {
                        $this->_updateBalancesDirectly($dateIssued);
                    }
                } catch (\Exception $e) {
                    Log::warning("Invalid date_issued format: {$request->date_issued}");
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mutasi Kas (Multiple) berhasil',
                'journal' => $journal->load(['debt', 'cred'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create journal'
            ], 500);
        }
    }

    public function getJournalByWarehouse(int $warehouse, String $startDate, String $endDate, Request $request)
    {
        // 1. Ambil semua ID Chart of Account yang terikat dengan gudang ini
        $chartOfAccounts = ChartOfAccount::where('warehouse_id', $warehouse)->pluck('id')->toArray();

        // 2. Parsing Tanggal menggunakan Carbon secara aman
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        // 3. Eksekusi Query dengan Eager Loading
        $journals = Journal::with([
            'debt.warehouse:id,name,code',
            'cred.warehouse:id,name,code',
            'transaction.product',
            'user:id,name,email'
        ])
            ->where(function ($mainQuery) use ($chartOfAccounts, $warehouse, $startDate, $endDate) {

                // 🟢 KONDISI A: Berdasarkan Chart of Accounts + Filter Tanggal
                $mainQuery->where(function ($query) use ($chartOfAccounts, $startDate, $endDate) {
                    $query->where(function ($subQuery) use ($chartOfAccounts) {
                        $subQuery->whereIn('debt_id', $chartOfAccounts)
                            ->orWhereIn('cred_id', $chartOfAccounts);
                    })
                        ->whereBetween('date_issued', [$startDate, $endDate]);
                })

                    // 🟢 KONDISI B: DIBUNGKUS AMAN! (Akun khusus bernilai 9 + Gudang + Filter Tanggal)
                    ->orWhere(function ($query) use ($warehouse, $startDate, $endDate) {
                        $query->where(function ($subQuery) {
                            $subQuery->where('debt_id', 9)
                                ->orWhere('cred_id', 9);
                        })
                            ->where('warehouse_id', $warehouse)
                            ->whereBetween('date_issued', [$startDate, $endDate]);
                    });
            })
            ->orderBy('date_issued', $request->sort ?? 'desc')
            ->get();

        // 4. Return Data menggunakan Resource ke Android
        return new AccountResource($journals, true, "Successfully fetched journals");
    }

    public function getExpenses($warehouse, $startDate, $endDate)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $expenses = Journal::with('warehouse', 'cred:id,name')
            ->where(function ($query) use ($warehouse) {
                if ($warehouse === "all") {
                    $query;
                } else {
                    $query->where('warehouse_id', $warehouse);
                }
            })
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where('trx_type', 'Pengeluaran')
            ->orderBy('id', 'desc')
            ->get();
        return new AccountResource($expenses, true, "Successfully fetched chart of accounts");
    }

    public function getWarehouseBalance($endDate)
    {
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();
        $previousDate = $endDate->copy()->subDay()->toDateString(); // Tanggal untuk mencari saldo awal

        // --- Perbaikan Kinerja: Pre-fetch data jurnal dan saldo sebelumnya dalam satu/dua kueri ---

        // 1. Ambil semua ChartOfAccount yang relevan
        $chartOfAccounts = ChartOfAccount::with(['account', 'limit'])->whereIn('account_id', [1, 2])->get();

        Log::info("Found " . $chartOfAccounts->count() . " chart of accounts.");

        // Dapatkan semua ID akun untuk kueri berikutnya
        $allAccountIds = $chartOfAccounts->pluck('id')->toArray();

        // 2. Pre-fetch saldo akhir hari sebelumnya untuk SEMUA akun yang relevan
        // Menggunakan array asosiatif [chart_of_account_id => ending_balance] untuk look-up cepat
        $previousDayBalances = AccountBalance::whereIn('chart_of_account_id', $allAccountIds)
            ->where('balance_date', $previousDate)
            ->pluck('ending_balance', 'chart_of_account_id')
            ->toArray();
        Log::info("Fetched " . count($previousDayBalances) . " previous day balances for {$previousDate}.");


        // 3. Pre-fetch total debit aktivitas untuk HANYA tanggal $endDate
        $dailyDebits = Journal::selectRaw('debt_id as account_id, SUM(amount) as total_amount')
            ->whereIn('debt_id', $allAccountIds)
            ->whereBetween('date_issued', [$previousDate, $endDate])
            // HANYA AKTIVITAS HARI INI
            ->groupBy('debt_id')
            ->pluck('total_amount', 'account_id')
            ->toArray();
        Log::info("Fetched " . count($dailyDebits) . " daily debit sums for {$endDate->toDateString()}.");


        // 4. Pre-fetch total credit aktivitas untuk HANYA tanggal $endDate
        $dailyCredits = Journal::selectRaw('cred_id as account_id, SUM(amount) as total_amount')
            ->whereIn('cred_id', $allAccountIds)
            ->whereBetween('date_issued', [$previousDate, $endDate])
            // HANYA AKTIVITAS HARI INI
            ->groupBy('cred_id')
            ->pluck('total_amount', 'account_id')
            ->toArray();
        Log::info("Fetched " . count($dailyCredits) . " daily credit sums for {$endDate->toDateString()}.");


        // --- Logic untuk memeriksa dan memicu update saldo yang hilang ---
        $missingDatesToUpdate = [];
        foreach ($allAccountIds as $accountId) {
            // Memeriksa keberadaan saldo menggunakan chart_of_account_id
            if (!isset($previousDayBalances[$accountId])) {
                // Jika saldo hari sebelumnya tidak ditemukan di account_balances
                $missingDatesToUpdate[$previousDate] = true; // Tambahkan tanggal ini ke daftar
                Log::warning("Missing AccountBalance record for account ID {$accountId} on {$previousDate}. Will trigger update.");
            }
        }

        foreach (array_keys($missingDatesToUpdate) as $date) {
            Log::info("Calling _updateBalancesDirectly for missing date: {$date}");
            // Memanggil fungsi baru secara langsung di controller
            $this->_updateBalancesDirectly($date);
        }
        // --- Akhir logic pemicu ---

        // --- PENTING: Re-fetch previousDayBalances setelah _updateBalancesDirectly dipanggil ---
        // Ini memastikan bahwa jika _updateBalancesDirectly baru saja menambahkan data,
        // data tersebut akan tersedia untuk perhitungan saldo selanjutnya dalam permintaan ini.
        if (!empty($missingDatesToUpdate)) {
            Log::info("Re-fetching previous day balances after direct updates.");
            $previousDayBalances = AccountBalance::whereIn('chart_of_account_id', $allAccountIds)
                ->where('balance_date', $previousDate)
                ->pluck('ending_balance', 'chart_of_account_id')
                ->toArray();
            Log::info("Re-fetched " . count($previousDayBalances) . " previous day balances.");
        }


        // --- Perhitungan Saldo per Akun ---
        foreach ($chartOfAccounts as $chartOfAccount) {
            // Mengambil saldo awal dari previousDayBalances atau fallback ke st_balance
            // Menggunakan chart_of_account_id untuk look-up di previousDayBalances
            $initBalance = $previousDayBalances[$chartOfAccount->id] ?? ($chartOfAccount->st_balance ?? 0.00);
            $normalBalance = $chartOfAccount->account->status ?? '';

            // Mengambil debit/credit hari ini dari pre-fetched arrays
            $debitToday = $dailyDebits[$chartOfAccount->id] ?? 0.00;
            $creditToday = $dailyCredits[$chartOfAccount->id] ?? 0.00;

            // Hitung saldo akhir
            $chartOfAccount->balance = $initBalance + ($normalBalance === 'D' ? $debitToday - $creditToday : $creditToday - $debitToday);
        }

        // --- Filter cash/bank accounts ---
        // Filter di sini harus menggunakan relasi 'account' karena acc_id ada di sana
        $sumtotalCash = $chartOfAccounts->filter(function ($coa) {
            return ($coa->account && $coa->account->id === 1 && $coa->id !== 1); // Asumsi acc_id 1 untuk Cash
        });
        $sumtotalBank = $chartOfAccounts->filter(function ($coa) {
            return ($coa->account && $coa->account->id === 2); // Asumsi acc_id 2 untuk Bank
        });


        // Ambil warehouse
        $warehouses = Warehouse::with('zone')->where('status', '!=', 0)->orderBy('name')->get();

        $totalProfitMonthly = Journal::selectRaw('
        SUM(CASE WHEN fee_amount > 0 THEN fee_amount ELSE 0 END) as total_fee,
        warehouse_id
                ')
            ->whereBetween('date_issued', [
                Carbon::parse($endDate)->startOfMonth(),
                Carbon::parse($endDate)->endOfMonth()
            ])
            ->where('warehouse_id', '!=', 1)
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id'); // 👈 ini penting


        $data = [
            'warehouse' => $warehouses->map(function ($w) use ($chartOfAccounts, $totalProfitMonthly, $endDate) {
                return [
                    'id' => $w->id,
                    'name' => $w->name,
                    'zone_id' => $w->zone->id ?? null,
                    'status' => $w->status,
                    'is_open' => $w->is_open,
                    'updated_at' => $w->updated_at,
                    // Filter di sini juga harus menggunakan relasi 'account'
                    'cash' => $chartOfAccounts->filter(function ($coa) use ($w) {
                        return ($coa->account && $coa->account->id === 1 && $coa->warehouse_id === $w->id);
                    })->sum('balance'),
                    'bank' => $chartOfAccounts->filter(function ($coa) use ($w) {
                        return ($coa->account && $coa->account->id === 2 && $coa->warehouse_id === $w->id);
                    })->sum('balance'),
                    'average_profit' => $w->id === 1
                        ? 0
                        : (
                            isset($totalProfitMonthly[$w->id])
                            ? $totalProfitMonthly[$w->id]->total_fee / $this->countDaysInMonth($endDate)
                            : 0
                        ),
                    'total_limit' => AccountLimit::whereHas('chartOfAccount', function ($q) use ($w) {
                        $q->where('warehouse_id', $w->id);
                    })->sum('limit_amount'),
                    'total_cash_limit' => AccountLimit::whereHas('chartOfAccount', function ($q) use ($w) {
                        $q->where('warehouse_id', $w->id)->where('account_id', 1);
                    })->sum('limit_amount'),
                    'total_bank_limit' => AccountLimit::whereHas('chartOfAccount', function ($q) use ($w) {
                        $q->where('warehouse_id', $w->id)->where('account_id', 2);
                    })->sum('limit_amount'),
                ];
            }),
            'totalCash' => $sumtotalCash->sum('balance'),
            'totalBank' => $sumtotalBank->sum('balance'),
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * Menghitung dan memperbarui saldo akun di tabel account_balances untuk tanggal tertentu.
     * Ini adalah replika logika dari Artisan Command UpdateAccountBalances,
     * dieksekusi secara langsung dalam controller.
     *
     * @param string $dateToUpdate Tanggal untuk memperbarui saldo (YYYY-MM-DD).
     * @return void
     */
    protected function _updateBalancesDirectly(string $dateToUpdate): void
    {
        // Parsing tanggal untuk memastikan format yang benar
        $targetDate = Carbon::parse($dateToUpdate);

        Log::info("Directly updating account balances for date: {$targetDate->toDateString()}...");

        try {
            $chartOfAccounts = ChartOfAccount::all();

            Log::info("Total accounts found for direct update: " . $chartOfAccounts->count());

            foreach ($chartOfAccounts as $chartOfAccount) {
                // Mengambil saldo awal dari properti model chartOfAccount->st_balance
                // Ini adalah saldo kumulatif dari awal waktu hingga hari sebelumnya
                $initBalance = $chartOfAccount->st_balance ?? 0.00;

                // Menghitung total debit langsung dari database hingga targetDate
                $totalDebit = Journal::where('debt_id', $chartOfAccount->id)
                    ->where('date_issued', '<=', $targetDate->toDateString())
                    ->sum('amount');

                // Menghitung total credit langsung dari database hingga targetDate
                $totalCredit = Journal::where('cred_id', $chartOfAccount->id)
                    ->where('date_issued', '<=', $targetDate->toDateString())
                    ->sum('amount');

                // Mengambil normal balance dari relasi 'account'
                $normalBalance = $chartOfAccount->account->status ?? '';

                $endingBalance = 0;
                if ($normalBalance === 'D') { // Asumsi 'D' untuk Debit
                    $endingBalance = $initBalance + $totalDebit - $totalCredit;
                } else { // Asumsi 'C' untuk Credit
                    $endingBalance = $initBalance + $totalCredit - $totalDebit;
                }

                // Simpan atau perbarui saldo di tabel account_balances
                AccountBalance::updateOrCreate(
                    [
                        'chart_of_account_id' => $chartOfAccount->id,
                        'balance_date' => $targetDate->toDateString(),
                    ],
                    [
                        'ending_balance' => $endingBalance,
                    ]
                );
                Log::debug("Direct update: Account {$chartOfAccount->acc_code} ({$chartOfAccount->name}): Balance updated to {$endingBalance} for {$targetDate->toDateString()}");
            }

            Log::info("Direct account balances update completed for {$targetDate->toDateString()}.");
        } catch (\Exception $e) {
            Log::error("Error during direct balance update for date {$targetDate->toDateString()}: {$e->getMessage()}", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function getRevenueReport($startDate, $endDate)
    {
        $journal = new Journal();
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $startDateLastMonth = Carbon::parse($startDate)->subMonth()->startOfMonth();
        $endDateLastMonth = Carbon::parse($startDate)->subMonth()->endOfMonth();

        $revenue = $journal->with(['warehouse'])
            ->selectRaw('SUM(amount) as total, warehouse_id, SUM(fee_amount) + 0 as sumfee')
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->groupBy('warehouse_id')
            ->orderBy('sumfee', 'desc')
            ->get();

        $revenueLastMonth = $journal->with(['warehouse'])
            ->selectRaw('SUM(amount) as total, warehouse_id, SUM(fee_amount) + 0 as sumfee')
            ->whereBetween('date_issued', [$startDateLastMonth, $endDateLastMonth])
            ->groupBy('warehouse_id')
            ->orderBy('sumfee', 'desc')
            ->get();

        $data = [
            'revenue' => $revenue->map(function ($r) use ($startDate, $endDate) {
                $rv = $r->whereBetween('date_issued', [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay()
                ])
                    ->where('trx_type', '!=', 'Jurnal Umum')
                    ->where('warehouse_id', $r->warehouse_id)->get();
                return [
                    'warehouse' => $r->warehouse->name,
                    'warehouseId' => $r->warehouse_id,
                    'warehouse_code' => $r->warehouse->code,
                    'zone_id' => $r->warehouse->warehouse_zone_id,
                    'cash' => $rv->where('debt_id', (int) 2)->where('warehouse_id', '!=', (int) 1)->sum('amount'),
                    'transfer' => $rv->where('trx_type', 'Transfer Uang')->sum('amount'),
                    'tarikTunai' => $rv->where('trx_type', 'Tarik Tunai')->sum('amount'),
                    'voucher' => $rv->where('trx_type', 'Voucher & SP')->sum('amount'),
                    'accessories' => $rv->where('trx_type', 'Accessories')->sum('amount'),
                    'deposit' => $rv->where('trx_type', 'Deposit')->sum('amount'),
                    'bank_fee' => $rv->where('trx_type', 'Bank Fee')->sum('fee_amount'),
                    'trx' => $rv->count() - $rv->whereIn('trx_type', ['Pengeluaran', 'Mutasi Kas'])->count(),
                    'expense' => -$rv->where('trx_type', 'Pengeluaran')->sum('fee_amount'),
                    'fee' => doubleval($r->sumfee ?? 0)
                ];
            }),
            'revenue_last_month' => $revenueLastMonth->map(function ($r) use ($startDateLastMonth, $endDateLastMonth) {
                $rv = $r->whereBetween('date_issued', [
                    Carbon::parse($startDateLastMonth)->startOfDay(),
                    Carbon::parse($endDateLastMonth)->endOfDay()
                ])
                    ->where('trx_type', '!=', 'Jurnal Umum')
                    ->where('warehouse_id', $r->warehouse_id)->get();
                return [
                    'warehouse' => $r->warehouse->name,
                    'warehouseId' => $r->warehouse_id,
                    'warehouse_code' => $r->warehouse->code,
                    'zone_id' => $r->warehouse->warehouse_zone_id,
                    'cash' => $rv->where('debt_id', (int) 2)->where('warehouse_id', '!=', (int) 1)->sum('amount'),
                    'transfer' => $rv->where('trx_type', 'Transfer Uang')->sum('amount'),
                    'tarikTunai' => $rv->where('trx_type', 'Tarik Tunai')->sum('amount'),
                    'voucher' => $rv->where('trx_type', 'Voucher & SP')->sum('amount'),
                    'accessories' => $rv->where('trx_type', 'Accessories')->sum('amount'),
                    'deposit' => $rv->where('trx_type', 'Deposit')->sum('amount'),
                    'bank_fee' => $rv->where('trx_type', 'Bank Fee')->sum('fee_amount'),
                    'trx' => $rv->count() - $rv->whereIn('trx_type', ['Pengeluaran', 'Mutasi Kas'])->count(),
                    'expense' => -$rv->where('trx_type', 'Pengeluaran')->sum('fee_amount'),
                    'fee' => doubleval($r->sumfee ?? 0)
                ];
            }),
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function getRevenueReportByWarehouse(int $warehouseId, int $month, int $year)
    {
        $startDate = Carbon::parse("$year-$month-01")->startOfMonth();
        $endDate = Carbon::parse("$year-$month-01")->endOfMonth();

        $journal = new Journal();

        // Data harian
        $revenue = $journal->selectRaw("
            DATE(date_issued) as date,
            SUM(CASE WHEN debt_id = 2 THEN amount ELSE 0 END) as cash,
            SUM(CASE WHEN trx_type = 'Transfer Uang' THEN amount ELSE 0 END) as transfer,
            SUM(CASE WHEN trx_type = 'Tarik Tunai' THEN amount ELSE 0 END) as tarikTunai,
            SUM(CASE WHEN trx_type = 'Voucher & SP' THEN amount ELSE 0 END) as voucher,
            SUM(CASE WHEN trx_type = 'Deposit' THEN amount ELSE 0 END) as deposit,
            COUNT(*) - COUNT(CASE WHEN trx_type = 'Pengeluaran' THEN 1 ELSE NULL END) as trx,
            -SUM(CASE WHEN trx_type = 'Pengeluaran' THEN fee_amount ELSE 0 END) as expense,
            SUM(fee_amount) as fee
        ")
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where('warehouse_id', $warehouseId)
            ->whereNotIn('trx_type', ['Mutasi Kas', 'Jurnal Umum'])
            ->groupBy('date')
            ->get();

        // Total keseluruhan
        $totals = $journal->selectRaw("
            SUM(CASE WHEN debt_id = 2 THEN amount ELSE 0 END) as cash,
            SUM(CASE WHEN trx_type = 'Transfer Uang' THEN amount ELSE 0 END) as totalTransfer,
            SUM(CASE WHEN trx_type = 'Tarik Tunai' THEN amount ELSE 0 END) as totalTarikTunai,
            SUM(CASE WHEN trx_type = 'Voucher & SP' THEN amount ELSE 0 END) as totalVoucher,
            SUM(CASE WHEN trx_type = 'Deposit' THEN amount ELSE 0 END) as totalDeposit,
            COUNT(*) - COUNT(CASE WHEN trx_type = 'Pengeluaran' THEN 1 ELSE NULL END) as totalTrx,
            -SUM(CASE WHEN trx_type = 'Pengeluaran' THEN fee_amount ELSE 0 END) as totalExpense,
            SUM(fee_amount) as totalFee
        ")
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where('warehouse_id', $warehouseId)
            ->whereNotIn('trx_type', ['Mutasi Kas', 'Jurnal Umum'])
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'revenue' => $revenue,
                'totals' => $totals
            ]
        ], 200);
    }

    public function mutationHistory(int $account, string $startDate, string $endDate, Request $request)
    {
        $journal = new Journal();
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $journal = new Journal();
        $journals = $journal->with('debt.account', 'cred.account', 'warehouse', 'user')
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where(function ($query) use ($request) {
                $query->where('invoice', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%')
                    ->orWhere('amount', 'like', '%' . $request->search . '%');
            })
            ->where(function ($query) use ($account) {
                $query->where('debt_id', $account)
                    ->orWhere('cred_id', $account);
            })
            ->orderBy('date_issued', 'asc')
            ->paginate(10, ['*'], 'mutationHistory');

        $total = $journal->with('debt.account', 'cred.account', 'warehouse', 'user')->where('debt_id', $account)
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->orWhere('cred_id', $account)
            ->WhereBetween('date_issued', [$startDate, $endDate])
            ->orderBy('date_issued', 'asc')
            ->get();

        $initBalanceDate = Carbon::parse($startDate)->subDay(1)->endOfDay();

        $debt_total = $total->where('debt_id', $account)->sum('amount');
        $cred_total = $total->where('cred_id', $account)->sum('amount');

        $data = [
            'journals' => $journals,
            'initBalance' => $journal->endBalanceBetweenDate($account, '0000-00-00', $initBalanceDate),
            'endBalance' => $journal->endBalanceBetweenDate($account, '0000-00-00', $endDate),
            'debt_total' => $debt_total,
            'cred_total' => $cred_total,
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
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

    public function getRankByProfit()
    {
        $journal = new Journal();
        $startDate = Carbon::now()->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $revenue = $journal->with('warehouse')->selectRaw('SUM(fee_amount) as total, warehouse_id')
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where('warehouse_id', '!=', 1)
            ->groupBy('warehouse_id')
            ->orderBy('total', 'desc')
            ->get();

        $totalProfitMonthly = Journal::selectRaw('SUM(CASE WHEN fee_amount > 0 THEN fee_amount ELSE 0 END) as total_fee_positive, warehouse_id')
            ->whereBetween('date_issued', [Carbon::parse($startDate)->startOfMonth(), Carbon::parse($endDate)->endOfMonth()])
            ->where('warehouse_id', '!=', 1)
            ->groupBy('warehouse_id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'revenue' => $revenue,
                'totalProfitMonthly' => $totalProfitMonthly->map(function ($r) {
                    return [
                        'average_profit' => $r->total_fee_positive / $this->countDaysInMonth(Carbon::now()->startOfDay()),
                        'warehouse_id' => $r->warehouse_id
                    ];
                })
            ]
        ], 200);
    }

    public function updateConfirmStatus($id)
    {
        $journal = Journal::findOrFail($id);
        $journal->is_confirmed = !$journal->is_confirmed;
        $journal->save();

        $message = $journal->is_confirmed ? 'Journal has been confirmed' : 'Journal has been unconfirmed';
        return response()->json([
            'success' => true,
            'data' => $journal,
            'message' => $message
        ], 200);
    }

    public function updateConfirmStatusBatch(Request $request)
    {
        $ids = $request->journal_ids;

        foreach ($ids as $id) {
            $journal = Journal::findOrFail($id);
            $journal->is_confirmed = 1;
            $journal->save();
        }

        return response()->json([
            'success' => true,
            'data' => $journal,
            'message' => 'Journal has been updated'
        ], 200);
    }

    public function calcPercentegeTrxByWarehouse($startDate, $endDate)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $journal = DB::table('journals as j')
            ->leftJoin('chart_of_accounts as d', 'j.debt_id', '=', 'd.id')
            ->leftJoin('chart_of_accounts as c', 'j.cred_id', '=', 'c.id')
            ->join('warehouses as w', 'j.warehouse_id', '=', 'w.id')
            ->select(
                'j.warehouse_id',
                'w.name as warehouse_name',
                'w.code as warehouse_code',
                DB::raw('COUNT(DISTINCT j.id) as total'),
                DB::raw('SUM(CASE WHEN j.is_confirmed = 1 THEN 1 ELSE 0 END) as confirmed_count')
            )
            ->whereBetween('j.date_issued', [$startDate, $endDate])
            ->where('j.warehouse_id', '!=', 1)
            ->where(function ($query) {
                $query->where('d.account_id', 2)
                    ->orWhere('c.account_id', 2);
            })
            ->groupBy('j.warehouse_id', 'w.name', 'w.code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $journal
        ], 200);
    }

    public function mutationJournal($startDate, $endDate)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $journal = Journal::with(['debt.warehouse' => function ($query) {
            $query->select('id', 'name', 'latitude', 'longitude');
        }, 'cred'])
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where('trx_type', 'Mutasi Kas')
            ->whereHas('debt', function ($query) {
                $query->where('account_id', 1);
            })
            ->orderBy('date_issued', 'desc')
            ->get();
        return response()->json([
            'success' => true,
            'data' => $journal
        ], 200);
    }

    public function getJournalByInvoiceNumber($invoice_number)
    {
        $journal = Journal::with(['debt.warehouse' => function ($query) {
            $query->select('id', 'name');
        }, 'cred'])->where('invoice', $invoice_number)->first();
        return response()->json([
            'success' => true,
            'data' => $journal
        ], 200);
    }

    public function updateDeliveryStatus(int $id, $status = 1)
    {
        $journal = Journal::findOrFail($id);
        $journal->status = $status;
        $journal->save();
        return response()->json([
            'success' => true,
            'data' => $journal,
            'message' => 'Delivery status has been updated'
        ], 200);
    }

    public function getProfitLossReport($warehouse, $month, $year)
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = Carbon::create($year, $month, 1)->endOfMonth();

        $journal = Journal::selectRaw($warehouse === 'all' ? 'trx_type, SUM(CASE WHEN fee_amount > 0 THEN fee_amount ELSE 0 END) as total_fee_positive, SUM(CASE WHEN fee_amount < 0 THEN fee_amount ELSE 0 END) as total_fee_negative' : 'trx_type, warehouse_id, SUM(CASE WHEN fee_amount > 0 THEN fee_amount ELSE 0 END) as total_fee_positive, SUM(CASE WHEN fee_amount < 0 THEN fee_amount ELSE 0 END) as total_fee_negative')
            ->whereNotIn('trx_type', ['Mutasi Kas', 'Jurnal Umum'])
            ->whereBetween('date_issued', [$start, $end])
            ->when(
                $warehouse !== 'all',
                fn($q) =>
                $q->where('warehouse_id', $warehouse)
                    ->groupBy('trx_type', 'warehouse_id')
                    ->orderBy('total_fee_positive', 'desc')
            )
            ->when(
                $warehouse === 'all',
                fn($q) =>
                $q->groupBy('trx_type')
                    ->orderBy('total_fee_positive', 'desc')
            )
            ->get();

        $warehouse_data = Warehouse::with([
            'contact:id,name',
            'contact.employee:id,contact_id',
            'contact.employee.payroll' => fn($q) =>
            $q->where('payroll_date', Carbon::parse($end)->toDateString())->limit(1)
        ])
            ->select('id', 'name', 'contact_id')
            ->when(
                $warehouse !== 'all',
                fn($q) =>
                $q->where('id', $warehouse)
            )
            ->get();

        $payrollTotal = Payroll::selectRaw('SUM(total_gross_pay) as total_gross_pay, SUM(total_commissions) as total_commissions, SUM(total_allowances) as total_allowances, SUM(total_deductions) as total_deductions, SUM(net_pay) as net_pay')
            ->where('payroll_date', Carbon::parse($end)->toDateString())
            ->first();
        $expenses = Journal::with('debt:id,name')
            ->select('id', 'warehouse_id', 'debt_id', 'description', 'trx_type', 'fee_amount', 'date_issued')
            ->when(
                $warehouse !== 'all',
                fn($q) =>
                $q->where('warehouse_id', $warehouse)
            )
            ->whereBetween('date_issued', [$start, $end])
            ->where('trx_type', 'Pengeluaran')
            ->get();

        $data = [
            'warehouse_data' => $warehouse_data,
            'journal' => $journal,
            'payrollTotal' => $payrollTotal,
            'expenses' => $expenses
        ];



        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function createDelivery(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:pick_up,delivery',
            'amount' => 'required|numeric|gt:0',
            'destination_id' => 'required|exists:warehouses,id',
            'trx_type' => 'required|string',
            'priority' => 'required|string|in:low,medium,high,urgent'
        ]);

        if ($request->type !== 'pick_up') {
            $request->validate([
                'courier_id' => 'required|exists:employees,id'
            ]);
        }

        // 1. Ambil Akun Tujuan
        $destinationAccount = ChartOfAccount::where('warehouse_id', $request->destination_id)
            ->where('is_primary_cash', true)
            ->first();

        // 2. Ambil Akun Asal (Gudang Pusat / ID 1)
        $sourceAccount = ChartOfAccount::where('warehouse_id', 1)
            ->where('is_primary_cash', true)
            ->first();

        // Validasi Keberadaan Akun
        if (!$destinationAccount || !$sourceAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Akun kas utama untuk gudang asal atau tujuan tidak ditemukan.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $currentUserId = auth()->id();

            $journal = Journal::create([
                'invoice' => Journal::invoice_journal(),
                'date_issued' => now(),
                'debt_id' => $destinationAccount->id,
                'cred_id' => $sourceAccount->id,
                'amount' => $request->amount,
                'is_confirmed' => 1,
                'status' => 1,
                'fee_amount' => 0,
                'trx_type' => 'Mutasi Kas',
                'description' => $request->description ?? "Penambahan Kas",
                'user_id' => $currentUserId,
                'warehouse_id' => $request->destination_id
            ]);

            $journal->delivery()->create([
                'source_account_id' => $sourceAccount->id,
                'destination_account_id' => $destinationAccount->id,
                'courier_id' => $request->courier_id,
                'received_by_id' => $currentUserId,
                'status' => $request->type === "pick_up" ? "picked_up" : "pending",
                'priority' => $request->priority ?? 'low'
            ]);

            // Simpan semua perubahan ke database
            DB::commit();

            // =========================================================================
            // 🔔 PENGIRIMAN NOTIFIKASI (Tepat setelah DB::commit())
            // =========================================================================
            if ($sourceAccount->account_id == 1 && $sourceAccount->warehouse_id == 1) {
                $employee = Employee::find($request->courier_id);

                if ($request->courier_id) {
                    $user = $employee->contact->user;

                    if ($user) {
                        $user->notify(new SendPushNotification(
                            'Permintaan Kirim Uang',
                            "Kamu memiliki tugas baru untuk Invoice: {$journal->invoice}",
                            [
                                'journal_id' => (string) $journal->id,
                                'type' => 'delivery_tasks'
                            ]
                        ));
                    }
                }
            }
            // =========================================================================

            return response()->json([
                'success' => true,
                'data' => $journal->load('delivery'),
                'message' => 'Delivery created successfully'
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Create Delivery Error: " . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
            ], 500);
        }
    }

    public function yearlyProfitReport(?string $year = null)
    {
        $selectedYear = $year ? (int) $year : now()->year;

        // 1. Ambil data dari DB dalam bentuk Key-Value: [bulan => total]
        $profitByMonth = Journal::selectRaw('MONTH(date_issued) as month, SUM(fee_amount) as total')
            ->whereYear('date_issued', $selectedYear)
            ->groupByRaw('MONTH(date_issued)')
            ->pluck('total', 'month'); // Contoh output: [1 => 310000000, 2 => 345000000]

        // 2. Loop dari bulan 1 - 12. Jika bulan tidak ada di DB, isi dengan 0
        $monthlyRevenue = collect(range(1, 12))->map(function ($month) use ($profitByMonth) {
            return (float) ($profitByMonth->get($month) ?? 0);
        })->values();

        return response()->json([
            'success' => true,
            'year'    => $selectedYear,
            'data'    => $monthlyRevenue // Output: [310000000, 345000000, ..., 0]
        ]);
    }
}
