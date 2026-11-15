<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('accounting:update-balances command updates balances for yesterday by default and supports upsert', function () {
    $debitCategory = Account::create([
        'name' => 'Kas & Bank',
        'code' => '10100',
        'type' => 'Assets',
        'status' => 'D',
    ]);

    $creditCategory = Account::create([
        'name' => 'Kewajiban',
        'code' => '20100',
        'type' => 'Liabilities',
        'status' => 'C',
    ]);

    $coaDebit = ChartOfAccount::create([
        'account_id' => $debitCategory->id,
        'code' => '10100-001',
        'name' => 'Kas Utama',
        'st_balance' => 100000,
    ]);

    $coaCredit = ChartOfAccount::create([
        'account_id' => $creditCategory->id,
        'code' => '20100-001',
        'name' => 'Utang Usaha',
        'st_balance' => 50000,
    ]);

    $warehouse = Warehouse::create([
        'code' => 'WH1',
        'name' => 'Cabang 1',
        'address' => 'Jl. Test 123',
        'chart_of_account_id' => $coaDebit->id,
    ]);

    $user = User::factory()->create([
        'warehouse_id' => $warehouse->id,
    ]);

    $yesterday = Carbon::yesterday()->toDateString();

    Journal::create([
        'date_issued' => $yesterday,
        'invoice' => 'JR.TEST.0000001',
        'description' => 'Test transaksi kemarin',
        'debt_id' => $coaDebit->id,
        'cred_id' => $coaCredit->id,
        'amount' => 25000,
        'fee_amount' => 0,
        'trx_type' => 'Jurnal Umum',
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
    ]);

    $this->artisan('accounting:update-balances')
        ->assertSuccessful();

    $debitBalance = AccountBalance::where('chart_of_account_id', $coaDebit->id)
        ->where('balance_date', $yesterday)
        ->first();

    $creditBalance = AccountBalance::where('chart_of_account_id', $coaCredit->id)
        ->where('balance_date', $yesterday)
        ->first();

    expect($debitBalance)->not->toBeNull()
        ->and((float) $debitBalance->ending_balance)->toBe(125000.0)
        ->and($creditBalance)->not->toBeNull()
        ->and((float) $creditBalance->ending_balance)->toBe(75000.0);

    // Run a second time after adding another journal to verify upsert updates existing rows
    Journal::create([
        'date_issued' => $yesterday,
        'invoice' => 'JR.TEST.0000002',
        'description' => 'Test transaksi tambahan',
        'debt_id' => $coaCredit->id,
        'cred_id' => $coaDebit->id,
        'amount' => 10000,
        'fee_amount' => 0,
        'trx_type' => 'Jurnal Umum',
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
    ]);

    $this->artisan('accounting:update-balances', ['--date' => $yesterday])
        ->assertSuccessful();

    expect(AccountBalance::where('balance_date', $yesterday)->count())->toBe(2)
        ->and((float) $debitBalance->fresh()->ending_balance)->toBe(115000.0)
        ->and((float) $creditBalance->fresh()->ending_balance)->toBe(65000.0);
});
