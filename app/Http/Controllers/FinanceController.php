<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\ChartOfAccount;
use App\Models\Finance;
use App\Models\Journal;
use App\Models\LogActivity;
use App\Models\User;
use App\Notifications\SendPushNotification;
use App\Services\EmployeeReceivableService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(string | int $contact, string $financeType)
    {
        // Pecah string berpemisah koma menjadi array:
        // "EmployeeReceivable R,InstallmentReceivable R" -> ["EmployeeReceivable R", "InstallmentReceivable R"]
        // "EmployeeReceivable" -> ["EmployeeReceivable"]
        $types = explode(',', urldecode($financeType));

        $finance = Finance::with(['contact', 'account'])
            ->when($contact !== "All", function ($query) use ($contact) {
                $query->where('contact_id', $contact);
            })
            ->whereIn('finance_type', $types) // Masukkan array $types hasil explode
            ->latest('created_at')
            ->paginate(10)
            ->onEachSide(0);

        // Group By juga disesuaikan agar akumulasi 'sisa' mengikuti filter tipe yang sama
        $financeGroupByContactId = Finance::with('contact')
            ->selectRaw('contact_id, SUM(bill_amount) as tagihan, SUM(payment_amount) as terbayar, SUM(bill_amount) - SUM(payment_amount) as sisa, finance_type')
            ->whereIn('finance_type', $types) // Tambahkan filter ini agar group by tidak menghitung tipe lain
            ->groupBy('contact_id', 'finance_type')
            ->get();

        $data = [
            'finance' => $finance,
            'financeGroupByContactId' => $financeGroupByContactId
        ];

        return new AccountResource($data, true, "Successfully fetched finances");
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
        // 1. Jalankan Validasi TERLEBIH DAHULU sebelum proses apapun
        $validated = $request->validate([
            'amount'      => 'required|numeric|min:1',
            'description' => 'required|string|max:160',
            'contact_id'  => 'required|exists:contacts,id',
            'debt_id'     => 'required|exists:chart_of_accounts,id',
            'cred_id'     => 'required|exists:chart_of_accounts,id',
            'type'        => [
                'required',
                Rule::in([
                    'Payable',
                    'Receivable',
                    'EmployeeReceivable',
                    'InstallmentReceivable',
                    'EmployeeReceivable R',
                    'InstallmentReceivable R',
                    'EmployeeReceivable X',
                    'InstallmentReceivable X',
                ])
            ],
            'date_issued' => 'nullable|date',
        ]);

        // 2. Parse Tanggal setelah validasi sukses
        $dateIssued = $request->date_issued ? Carbon::parse($request->date_issued) : Carbon::now();

        DB::beginTransaction();
        try {
            // 3. Generate Nomor Invoice DI DALAM Transaction agar aman dari race condition
            $financeModel = new Finance();
            $invoiceNumber = $financeModel->invoice_finance((int) $request->contact_id, $request->type);

            // Tentukan Chart of Account ID berdasarkan Tipe Finance
            $chartOfAccountId = ($request->type === 'Payable') ? $request->cred_id : $request->debt_id;

            // 4. Buat Record Finance
            $finance = Finance::create([
                'date_issued' => $dateIssued->format('Y-m-d H:i:s'),
                'due_date' => $dateIssued->copy()->addDays(30)->format('Y-m-d H:i:s'),
                'invoice' => $invoiceNumber,
                'description' => $request->description,
                'bill_amount' => $request->amount,
                'payment_amount' => 0,
                'payment_status' => 0,
                'payment_nth' => 0,
                'finance_type' => $request->type,
                'contact_id' => $request->contact_id,
                'user_id' => Auth::id(),
                'chart_of_account_id' => $chartOfAccountId,
            ]);

            // 5. Buat Record Journal
            Journal::create([
                'date_issued' => $dateIssued->format('Y-m-d H:i:s'),
                'invoice' => $invoiceNumber,
                'description' => $request->description,
                'debt_id' => $request->debt_id,
                'cred_id' => $request->cred_id,
                'amount' => $request->amount,
                'fee_amount' => 0,
                'status' => 1,
                'rcv_pay' => $request->type,
                'payment_status' => 0,
                'payment_nth' => 0,
                'user_id' => Auth::id(),
                'warehouse_id' => Auth::user()->warehouse_id ?? 1, // Dinamis berdasarkan user (opsional)
            ]);

            DB::commit();

            if (in_array($request->type, ['EmployeeReceivable R', 'InstallmentReceivable R'])) {
                // Gunakan LOWERCASE jika di database menggunakan huruf kecil
                $admins = User::whereIn('role', ['Administrator', 'Super Admin', 'administrator', 'super admin'])->get();

                if ($admins->isEmpty()) {
                    Log::warning('Notifikasi batal dikirim: Tidak ada admin yang ditemukan.');
                } else {
                    try {
                        Notification::send($admins, new SendPushNotification(
                            'Pengajuan Kasbon/Cicilan Baru',
                            "Pengajuan Kasbon/Cicilan baru menunggu persetujuan",
                            [
                                'type' => 'receivable_request',
                                'finance_id' => $finance->id,
                            ]
                        ));
                        Log::info('Notifikasi berhasil diproses ke ' . $admins->count() . ' admin.');
                    } catch (\Exception $e) {
                        Log::error('Gagal kirim notifikasi: ' . $e->getMessage());
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => $request->type === 'Payable' ? 'Payable created successfully' : 'Receivable created successfully',
                'data' => [
                    'invoice' => $invoiceNumber
                ]
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();

            Log::error('Finance Store Error: ' . $th->getMessage(), [
                'user_id' => Auth::id(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan transaksi. ' . $th->getMessage()
            ], 500);
        }
    }

    public function storeSaving(Request $request)
    {
        $dateIssued = $request->date_issued ? Carbon::parse($request->date_issued) : Carbon::now();
        $invoice_number = Finance::invoice_saving($request->contact_id);

        $request->validate([
            'amount' => 'required|numeric',
            'description' => 'required|max:160',
            'contact_id' => 'required|exists:contacts,id',
            'debt_id' => 'required|exists:chart_of_accounts,id',
        ]);

        DB::beginTransaction();
        try {
            Finance::create([
                'date_issued' => $dateIssued,
                'due_date' => $dateIssued->copy()->addDays(30),
                'invoice' => $invoice_number,
                'description' => "Simpanan Wajib Karyawan. Note: " . $request->description,
                'bill_amount' => $request->amount,
                'payment_amount' => 0,
                'payment_status' => 0,
                'payment_nth' => 0,
                'finance_type' => "Saving",
                'contact_id' => $request->contact_id,
                'user_id' => Auth::user()->id,
                'chart_of_account_id' => ChartOfAccount::SAVING_ACCOUNT
            ]);

            Journal::create([
                'date_issued' => $dateIssued,
                'invoice' => $invoice_number,
                'description' => $request->description,
                'debt_id' => $request->debt_id,
                'cred_id' => ChartOfAccount::SAVING_ACCOUNT,
                'amount' => $request->amount,
                'fee_amount' => 0,
                'status' => 1,
                'rcv_pay' => $request->type,
                'payment_status' => 0,
                'payment_nth' => 0,
                'user_id' => Auth::user()->id,
                'warehouse_id' => 1
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payable created successfully'
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error($th->getMessage());
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function storeSavingMultiple(Request $request)
    {
        $dateIssued = $request->date_issued ? Carbon::parse($request->date_issued) : Carbon::now();


        $request->validate([
            'amount' => 'required|numeric',
            'contact_ids' => 'required|array',
            'contact_ids.*' => 'required|exists:contacts,id',
            'debt_id' => 'required|exists:chart_of_accounts,id',
        ]);

        DB::beginTransaction();
        try {
            foreach ($request->contact_ids as $contact_id) {
                $invoice_number = Finance::invoice_saving($contact_id);
                Finance::create([
                    'date_issued' => $dateIssued,
                    'due_date' => $dateIssued->copy()->addDays(30),
                    'invoice' => $invoice_number,
                    'description' => "Simpanan Wajib Karyawan",
                    'bill_amount' => $request->amount,
                    'payment_amount' => 0,
                    'payment_status' => 0,
                    'payment_nth' => 0,
                    'finance_type' => "Saving",
                    'contact_id' => $contact_id,
                    'user_id' => Auth::user()->id,
                    'chart_of_account_id' => ChartOfAccount::SAVING_ACCOUNT
                ]);

                Journal::create([
                    'date_issued' => $dateIssued,
                    'invoice' => $invoice_number,
                    'description' => "Simpanan Wajib Karyawan",
                    'debt_id' => $request->debt_id,
                    'cred_id' => ChartOfAccount::SAVING_ACCOUNT,
                    'amount' => $request->amount,
                    'fee_amount' => 0,
                    'status' => 1,
                    'rcv_pay' => $request->type,
                    'payment_status' => 0,
                    'payment_nth' => 0,
                    'user_id' => Auth::user()->id,
                    'warehouse_id' => 1
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Berhasil membuat simpanan wajib karyawan (multiple)'
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error($th->getMessage());
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $finance = Finance::find($id);

        $issued = Carbon::parse($finance->date_issued);
        if (!$issued->isToday() && auth()->user()->role !== 'Super Admin' && $finance->type !== 'EmployeeReceivable') {
            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus finance. Tanggal finance tidak boleh lebih kecil dari tanggal sekarang.'
            ], 400);
        }

        $invoice = $finance->invoice;

        $checkData = Finance::where('invoice', $invoice)->where('finance_type', $finance->finance_type)->get();
        // dd($checkData->count());

        if ($finance->payment_status == 1) {
            return response()->json([
                'status' => false,
                'message' => 'Pembayaran sudah dilakukan'
            ]);
        }


        if ($finance->payment_status == 0 && $finance->payment_nth == 0 && $checkData->count() > 1) {
            return response()->json([
                'status' => false,
                'message' => 'Sudah terjadi pembayaran'
            ]);
        }

        $log = new LogActivity();

        DB::beginTransaction();
        try {
            Journal::where('invoice', $invoice)->where('payment_status', $finance->payment_status)->where('payment_nth', $finance->payment_nth)->delete();
            $finance->delete();

            $financeAmount = $finance->bill_amount > 0 ? $finance->bill_amount : $finance->payment_amount;
            $billOrPayment = $finance->bill_amount > 0 ? 'bill' : 'payment';
            $log->create([
                'user_id' => auth()->user()->id,
                'warehouse_id' => 1,
                'activity' => $finance->finance_type . ' deleted',
                'description' => $finance->finance_type . ' with invoice: ' . $finance->invoice . ' ' . $billOrPayment . ' amount: ' . $financeAmount . ' deleted by ' . auth()->user()->name,
            ]);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Payable deleted successfully'
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error($th->getMessage());
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getFinanceByContactId(int $contactId, Request $request)
    {
        $finance = Finance::with(['contact']) // Account dilepas jika tidak dibutuhkan di level invoice group
            ->selectRaw('
            contact_id, 
            invoice, 
            finance_type,
            SUM(bill_amount) as tagihan, 
            SUM(payment_amount) as terbayar, 
            (SUM(bill_amount) - SUM(payment_amount)) as sisa
        ')
            ->where('contact_id', $contactId)
            ->when($request->filled('type'), function ($query) use ($request) {
                return $query->where('finance_type', $request->type);
            })
            // Filter opsional: Hanya ambil yang belum lunas saja (sisa > 0)
            ->when($request->boolean('unpaid_only'), function ($query) {
                return $query->havingRaw('(SUM(bill_amount) - SUM(payment_amount)) > 0');
            })
            ->groupBy('contact_id', 'invoice', 'finance_type')
            ->orderBy('invoice', 'desc')
            ->get();

        return new AccountResource($finance, true, "Successfully fetched finances");
    }

    public function getFinanceByType($contact, string $financeType, ?string $start = null, ?string $end = null)
    {
        $start = $start && $start !== 'null' ? Carbon::parse($start)->startOfDay() : Carbon::now()->startOfMonth();
        $end = $end && $end !== 'null' ? Carbon::parse($end)->endOfDay() : Carbon::now()->endOfMonth();

        // 1. Parse string comma-separated menjadi array
        // "EmployeeReceivable R,InstallmentReceivable R" -> ["EmployeeReceivable R", "InstallmentReceivable R"]
        $types = array_filter(explode(',', urldecode($financeType)));

        // 2. Detail Transaksi (Filtered by Date, Contact, & Finance Type)
        $finance = Finance::with(['contact', 'account'])
            ->when($contact !== "All", function ($query) use ($contact) {
                $query->where('contact_id', $contact);
            })
            ->when($financeType !== "All", function ($query) use ($types) {
                $query->whereIn('finance_type', $types);
            })
            ->whereBetween('date_issued', [$start, $end])
            ->latest('date_issued')
            ->get();

        // 3. Rekap Total Per Kontak (Lifetime - Filtered by Contact & Multi Finance Type)
        $financeGroupByContactId = Finance::selectRaw('
            finances.contact_id,
            contacts.name as contact_name,
            SUM(finances.bill_amount) as tagihan,
            SUM(finances.payment_amount) as terbayar,
            (SUM(finances.bill_amount) - SUM(finances.payment_amount)) as sisa
        ')
            ->join('contacts', 'contacts.id', '=', 'finances.contact_id')
            ->when($contact !== "All", function ($query) use ($contact) {
                $query->where('finances.contact_id', $contact);
            })
            ->when($financeType !== "All", function ($query) use ($types) {
                $query->whereIn('finances.finance_type', $types);
            })
            ->groupBy('finances.contact_id', 'contacts.name')
            ->orderBy('contacts.name')
            ->get();

        $data = [
            'finance' => $finance,
            'financeGroupByContactId' => $financeGroupByContactId
        ];

        return new AccountResource($data, true, "Successfully fetched finances");
    }

    public function getInvoiceValue(?string $invoice = null, ?int $contactId = null, ?string $financeType = null)
    {
        $query = Finance::selectRaw('SUM(bill_amount) - SUM(payment_amount) as sisa')->when($financeType, function ($query) use ($financeType) {
            return $query->where('finance_type', $financeType);
        });

        if ($invoice) {
            // Hitung per invoice
            $query->where('invoice', $invoice)->groupBy('invoice');
        } elseif ($contactId) {
            // Hitung per contact
            $query->where('contact_id', $contactId)->groupBy('contact_id');
        } else {
            // Kalau tidak ada filter, mungkin return semua?
            return 0;
        }

        return $query->value('sisa') ?? 0;
    }


    public function getFinanceData($invoice)
    {
        $pay_nth = Finance::where('invoice', $invoice)->where('payment_nth', 0)->first();
        return $pay_nth;
    }

    public function storePayment(Request $request)
    {
        $sisa = $this->getInvoiceValue(invoice: $request->invoice);
        if ($sisa <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Jumlah pembayaran melebihi sisa tagihan'
            ], 500);
        }

        $dateIssued = $request->date_issued ? Carbon::parse($request->date_issued) : Carbon::now();
        $finance = $this->getFinanceData(invoice: $request->invoice);
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'invoice' => 'required|exists:finances,invoice',
            'account_id' => 'required|exists:chart_of_accounts,id',
            'amount' => 'required|numeric|min:0|max:' . $sisa,
            'notes' => 'required',
        ]);

        $payment_nth = Finance::selectRaw('MAX(payment_nth) as payment_nth')->where('invoice', $request->invoice)->first()->payment_nth + 1;
        $payment_status = $this->getInvoiceValue(invoice: $request->invoice) == 0 ? 1 : 0;

        DB::beginTransaction();
        try {
            Finance::create([
                'date_issued' => $dateIssued,
                'due_date' => $finance->due_date,
                'invoice' => $request->invoice,
                'description' => $request->notes,
                'bill_amount' => 0,
                'payment_amount' => $request->amount,
                'payment_status' => $payment_status,
                'payment_nth' => $payment_nth,
                'finance_type' => $finance->finance_type,
                'contact_id' => $request->contact_id,
                'user_id' => Auth::user()->id,
                'chart_of_account_id' => $request->account_id,
            ]);

            Journal::create([
                'date_issued' => $dateIssued,
                'invoice' => $request->invoice,
                'description' => $request->notes,
                'debt_id' => $finance->finance_type == 'Receivable' ? $request->account_id : $finance->chart_of_account_id,
                'cred_id' => $finance->finance_type == 'Receivable' ? $finance->chart_of_account_id : $request->account_id,
                'amount' => $request->amount,
                'fee_amount' => 0,
                'status' => 1,
                'rcv_pay' => $this->getFinanceData(invoice: $request->invoice)->finance_type,
                'payment_status' => $payment_status,
                'payment_nth' => $payment_nth,
                'user_id' => Auth::user()->id,
                'warehouse_id' => 1
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully'
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error($th->getMessage());
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function depositWithdraw(Request $request)
    {
        $sisa = $this->getInvoiceValue(contactId: $request->contact_id);
        if ($sisa <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'Jumlah pembayaran melebihi sisa tagihan'
            ]);
        }

        $dateIssued = $request->date_issued ? Carbon::parse($request->date_issued) : Carbon::now();
        $finance = $this->getInvoiceValue(contactId: $request->contact_id);
        $request->validate(
            [
                'contact_id' => 'required|exists:contacts,id',
                'account_id' => 'required|exists:chart_of_accounts,id',
                'amount' => 'required|numeric|min:0|max:' . $sisa,
                'notes' => 'required',
            ],
            [
                'amount.max' => 'Jumlah pembayaran melebihi sisa tagihan : ' . number_format($sisa),
            ]
        );

        $payment_nth = Finance::selectRaw('MAX(payment_nth) as payment_nth')->where('contact_id', $request->contact_id)->first()->payment_nth + 1;
        $payment_status = $this->getInvoiceValue(contactId: $request->contact_id) == 0 ? 1 : 0;
        $invoice_number = Finance::invoice_saving($request->contact_id);

        DB::beginTransaction();
        try {
            Finance::create([
                'date_issued' => $dateIssued,
                'due_date' => $dateIssued,
                'invoice' => $invoice_number,
                'description' => $request->notes,
                'bill_amount' => 0,
                'payment_amount' => $request->amount,
                'payment_status' => $payment_status,
                'payment_nth' => $payment_nth,
                'finance_type' => "Saving",
                'contact_id' => $request->contact_id,
                'user_id' => Auth::user()->id,
                'chart_of_account_id' => $request->account_id,
            ]);

            Journal::create([
                'date_issued' => $dateIssued,
                'invoice' => $invoice_number,
                'description' => $request->notes,
                'debt_id' => ChartOfAccount::SAVING_ACCOUNT,
                'cred_id' => $request->account_id,
                'amount' => $request->amount,
                'fee_amount' => 0,
                'status' => 1,
                'rcv_pay' => "Saving",
                'payment_status' => $payment_status,
                'payment_nth' => $payment_nth,
                'user_id' => Auth::user()->id,
                'warehouse_id' => 1
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully'
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error($th->getMessage());
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function EmployeeRcvPayment(Request $request)
    {
        $sisa = $this->getInvoiceValue(contactId: $request->contact_id, financeType: $request->finance_type);
        if ($sisa <= 0) {
            return response()->json([
                'status' => false,
                'message' => 'Jumlah pembayaran melebihi sisa tagihan'
            ]);
        }

        $dateIssued = $request->date_issued ? Carbon::parse($request->date_issued) : Carbon::now();
        $request->validate(
            [
                'contact_id' => 'required|exists:contacts,id',
                'account_id' => 'required|exists:chart_of_accounts,id',
                'amount' => 'required|numeric|min:0|max:' . $sisa,
                'notes' => 'required',
            ],
            [
                'amount.max' => 'Jumlah pembayaran melebihi sisa tagihan : ' . number_format($sisa),
            ]
        );

        $payment_nth = Finance::selectRaw('MAX(payment_nth) as payment_nth')->where('contact_id', $request->contact_id)->first()->payment_nth + 1;
        $payment_status = $this->getInvoiceValue(contactId: $request->contact_id) == 0 ? 1 : 0;
        $invoice_number = Finance::payment_invoice($request->contact_id);

        DB::beginTransaction();
        try {
            Finance::create([
                'date_issued' => $dateIssued,
                'due_date' => $dateIssued,
                'invoice' => $invoice_number,
                'description' => $request->notes,
                'bill_amount' => 0,
                'payment_amount' => $request->amount,
                'payment_status' => $payment_status,
                'payment_nth' => $payment_nth,
                'finance_type' => $request->finance_type,
                'contact_id' => $request->contact_id,
                'user_id' => Auth::user()->id,
                'chart_of_account_id' => $request->account_id,
            ]);

            Journal::create([
                'date_issued' => $dateIssued,
                'invoice' => $invoice_number,
                'description' => $request->notes,
                'debt_id' => ChartOfAccount::EMPLOYEE_RECEIVABLE,
                'cred_id' => $request->account_id,
                'amount' => $request->amount,
                'fee_amount' => 0,
                'status' => 1,
                'rcv_pay' => $request->finance_type,
                'payment_status' => $payment_status,
                'payment_nth' => $payment_nth,
                'user_id' => Auth::user()->id,
                'warehouse_id' => 1
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully'
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error($th->getMessage());
            return response()->json([
                'status' => false,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function employeeReceivablePayment(
        Request $request,
        EmployeeReceivableService $service
    ) {
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'account_id' => 'required|exists:chart_of_accounts,id',
            'amount'     => 'required|numeric|min:1',
            'notes'      => 'required|string',
        ]);

        try {
            $result = $service->pay($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully',
                'data' => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function approveRequest(Finance $finance)
    {
        $user = $finance->user;
        Log::info('Approving finance request for user: ' . $user->id . '. finance_type: ' . $finance->finance_type);

        $updatedFinanceType = $finance->finance_type === "EmployeeReceivable R" ? 'EmployeeReceivable' : 'InstallmentReceivable';
        $finance->update(['finance_type' => $updatedFinanceType]);


        if ($user) {
            $user->notify(new SendPushNotification(
                'Pengajuan Kasbon/Cicilan Diterima',
                "Pengajuan Anda telah diterima, silahkan hubungi admin",
                [
                    'type' => 'receivable_request',
                    'finance_id' => $finance->id,
                ]
            ));
        }

        return response()->json([
            'success' => true,
            'message' => 'Request approved successfully'
        ], 200);
    }

    public function rejectRequest(Finance $finance)
    {
        $updatedFinanceType = $finance->finance_type === "EmployeeReceivable R" ? 'EmployeeReceivable X' : 'InstallmentReceivable X';
        $finance->update(['finance_type' => $updatedFinanceType]);

        $user = $finance->user;

        if ($user) {
            $user->notify(new SendPushNotification(
                'Pengajuan Kasbon/Cicilan Ditolak',
                "Pengajuan Anda telah ditolak, silahkan hubungi admin",
                [
                    'type' => 'receivable_request',
                    'finance_id' => $finance->id,
                ]
            ));
        }

        return response()->json([
            'success' => true,
            'message' => 'Request rejected successfully'
        ], 200);
    }
}
