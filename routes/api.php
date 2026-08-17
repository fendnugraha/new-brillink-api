<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CorrectionController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeWarningController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\LogActivityController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SalaryComponentController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WarehouseZoneController;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/android/login', [AuthenticatedSessionController::class, 'storeAndroid'])
    ->middleware('guest')
    ->name('android.login');

Route::get('/android/test-connection', function () {
    return response()->noContent();
})->middleware('guest');

Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::get('/user', function (Request $request) {
        return $request->user()->load([
            'warehouse',
            'warehouse.primaryCash.limit',
            'contact.employee.warningActive',
            'attendances' => function ($q) {
                $q->whereMonth('date', now()->format('m'))->whereYear('date', now()->format('Y'));
            }
        ]);
    });

    Route::get('checkin-status', function () {
        $user = auth()->user(); // Langsung ambil user tanpa query ulang

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        return response()->json([
            'has_checked_in' => (bool) $user->has_checked_in
        ]);
    });
    Route::apiResource('users', UserController::class);
    Route::get('get-all-users', [UserController::class, 'getAllUsers']);
    Route::put('users/{id}/update-password', [UserController::class, 'updatePassword']);
    Route::put('update-user-location', [UserController::class, 'updateUserLocation']);
    Route::post('/update-fcm-token', [UserController::class, 'updateFcmToken']);

    Route::get('get-category-accounts', function () {
        $accounts = Account::all();
        return new AccountResource($accounts, true, "Successfully fetched accounts");
    });

    Route::apiResource('accounts', ChartOfAccountController::class);
    Route::get('get-all-accounts', [ChartOfAccountController::class, 'getAllAccounts']);
    Route::get('get-account-by-account-id', [ChartOfAccountController::class, 'getAccountByAccountId']);
    Route::get('get-cash-and-bank', [ChartOfAccountController::class, 'getCashAndBank']);
    Route::apiResource('category-accounts', AccountController::class);
    Route::delete('delete-selected-account', [ChartOfAccountController::class, 'deleteAll']);
    Route::put('warehouse/{warehouse}/add-cash-bank/{id}', [ChartOfAccountController::class, 'addCashAndBankToWarehouse']);
    Route::get('get-cash-bank-by-warehouse/{warehouse}', [ChartOfAccountController::class, 'getCashAndBankByWarehouse']);
    Route::get('get-expense-accounts', [ChartOfAccountController::class, 'getExpenses']);
    Route::get('get-cash-bank-balance/{warehouse}/{endDate}', [ChartOfAccountController::class, 'getCashBankBalance']);
    Route::get('daily-dashboard', [ChartOfAccountController::class, 'dailyDashboard']);
    Route::put('update-account-limit/{id}', [ChartOfAccountController::class, 'updateAccountLimit']);
    Route::put('update-account-limit-batch', [ChartOfAccountController::class, 'updateAccountLimitBatch']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('product-categories', ProductCategoryController::class);
    Route::get('get-all-products', [ProductController::class, 'getAllProducts']);

    //contacts
    Route::apiResource('contacts', ContactController::class);
    Route::get('get-all-contacts', [ContactController::class, 'getAllContacts']);

    Route::apiResource('warehouse', WarehouseController::class);
    Route::get('get-all-warehouses', [WarehouseController::class, 'getAllWarehouses']);
    Route::put('toggle-lock-status-by-id/{id}', function (Warehouse $warehouse, $id) {
        return $warehouse->toggleLockStatusById($id);
    });

    //journals
    Route::apiResource('journals', JournalController::class);
    Route::post('create-transfer', [JournalController::class, 'createTransfer']);
    Route::post('create-voucher', [JournalController::class, 'createVoucher']);
    Route::post('create-deposit', [JournalController::class, 'createDeposit']);
    Route::post('create-mutation', [JournalController::class, 'createMutation']);
    Route::post('create-mutation-multiple', [JournalController::class, 'createMutationMultiple']);
    Route::get('get-journal-by-warehouse/{warehouse}/{startDate}/{endDate}', [JournalController::class, 'getJournalByWarehouse']);
    Route::get('get-expenses/{warehouse}/{startDate}/{endDate}', [JournalController::class, 'getExpenses']);
    Route::get('get-warehouse-balance/{endDate}', [JournalController::class, 'getWarehouseBalance']);
    Route::get('get-revenue-report/{startDate}/{endDate}', [JournalController::class, 'getRevenueReport']);
    Route::get('get-revenue-report-by-warehouse/{warehouseId}/{month}/{year}', [JournalController::class, 'getRevenueReportByWarehouse']);
    Route::get('mutation-history/{account}/{startDate}/{endDate}', [JournalController::class, 'mutationHistory']);
    Route::get('get-rank-by-profit', [JournalController::class, 'getRankByProfit']);
    Route::put('update-confirm-status/{id}', [JournalController::class, 'updateConfirmStatus']);
    Route::post('update-confirm-status-batch', [JournalController::class, 'updateConfirmStatusBatch']);
    Route::get('calculate-trx-by-warehouse/{startDate}/{endDate}', [JournalController::class, 'calcPercentegeTrxByWarehouse']);
    Route::get('mutation-journal/{startDate}/{endDate}', [JournalController::class, 'mutationJournal']);
    Route::get('get-journal-by-invoice-number/{invoice_number}', [JournalController::class, 'getJournalByInvoiceNumber']);
    Route::put('update-delivery-status/{id}/{status}', [JournalController::class, 'updateDeliveryStatus']);
    Route::get('get-profit-loss-report/{warehouse}/{month}/{year}', [JournalController::class, 'getProfitLossReport']);
    Route::post('create-delivery', [JournalController::class, 'createDelivery']);
    Route::post('create-delivery-multiple', [JournalController::class, 'createDeliveryMultiple']);


    //transactions
    Route::apiResource('transactions', TransactionController::class);
    Route::get('get-trx-vcr/{warehouse}/{startDate}/{endDate}', [TransactionController::class, 'getTrxVcr']);
    Route::get('get-tx-by-warehouse/{warehouse}/{startDate}/{endDate}', [TransactionController::class, 'getTxbyWarehouse']);

    //Finance
    Route::apiResource('finance', FinanceController::class);
    Route::get('finance-by-type/{contact}/{financeType}/{start?}/{end?}', [FinanceController::class, 'getFinanceByType']);
    Route::get('get-finance-by-contact-id/{contactId}', [FinanceController::class, 'getFinanceByContactId']);
    Route::post('store-payment', [FinanceController::class, 'storePayment']);
    Route::post('deposit-withdraw', [FinanceController::class, 'depositWithdraw']);
    Route::post('employee-rcv-payment', [FinanceController::class, 'employeeRcvPayment']);
    Route::post('store-saving', [FinanceController::class, 'storeSaving']);
    Route::post('store-saving-multiple', [FinanceController::class, 'storeSavingMultiple']);

    Route::get('log-activity/{startDate}/{endDate}/{warehouse}', [LogActivityController::class, 'index']);

    //Correction
    Route::apiResource('correction', CorrectionController::class);

    //Attendance
    Route::apiResource('attendance', AttendanceController::class);
    Route::post('create-attendance', [AttendanceController::class, 'createAttendance']);
    Route::post('create-attendance-manually', [AttendanceController::class, 'createAttendanceManually']);
    Route::get('attendance-check/{date}/{userId}', [AttendanceController::class, 'attendanceCheck']);
    Route::get('get-attendance-by-contact', [AttendanceController::class, 'getAttendanceByContact']);
    Route::get('get-contact-details', [AttendanceController::class, 'getContactDetails']);
    Route::get('get-attendances-by-contact-monthly/{contactId}/{year}/{month}', [AttendanceController::class, 'getAttendancesByContactMonthly']);

    //warehouse
    Route::get('get-all-warehouses', [WarehouseController::class, 'getAllWarehouses']);
    Route::put('update-warehouse-location/{warehouse}', [WarehouseController::class, 'updateWarehouseLocation']);
    Route::put('warehouse/{warehouse}/reset-location', [WarehouseController::class, 'resetLocation']);
    Route::get('get-warehouse-attendance/{date}', [AttendanceController::class, 'getWarehouseAttendance']);
    Route::get('get-attendance-monthly/{date}', [AttendanceController::class, 'getAttendanceMonthly']);
    Route::put('change-lock-status/{warehouse}', [WarehouseController::class, 'changeLockStatus']);
    Route::get('check-warehouse-status/{warehouse}', [WarehouseController::class, 'checkWarehouseStatus']);
    Route::get('get-nearest-warehouse', [WarehouseController::class, 'getNearestWarehouse']);
    Route::put('update-open-hours', [WarehouseController::class, 'updateOpenHours']);

    //zone
    Route::apiResource('zones', WarehouseZoneController::class);
    // Route::get('get-all-zones', [WarehouseZoneController::class, 'getAllZones']);

    //employees
    Route::apiResource('employees', EmployeeController::class);
    Route::post('store-payroll', [EmployeeController::class, 'storePayroll']);
    Route::get('get-payroll', [EmployeeController::class, 'getPayroll']);
    Route::get('get-payroll-by-date/{date}', [EmployeeController::class, 'getPayrollByDate']);

    Route::apiResource('employee-warnings', EmployeeWarningController::class);

    Route::apiResource('salary-components', SalaryComponentController::class);

    Route::apiResource('deliveries', DeliveryController::class);
    Route::prefix('deliveries/{delivery}')->group(function () {
        Route::post('/process', [DeliveryController::class, 'process']);   // Saat kurir mulai jalan
        Route::post('/complete', [DeliveryController::class, 'complete']); // Saat pengiriman selesai
        Route::post('/cancel', [DeliveryController::class, 'cancel']);     // Jika pengiriman dibatalkan
    });

    Route::apiResource('cash-flows', CashFlowController::class);

    Route::get('yearly-profit-report/{year?}', [JournalController::class, 'yearlyProfitReport']);

    Route::get('monthly-payroll-sum/{date?}', [PayrollController::class, 'monthlyPayrollSum']);

    //Notification area

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/mark-read', [NotificationController::class, 'markAsRead']);
});
