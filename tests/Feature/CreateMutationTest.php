<?php

use App\Models\Account;
use App\Models\CashFlow;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::create([
        'code' => 'HQ1',
        'name' => 'Headquarters',
        'is_open' => true,
    ]);

    $this->cashAccountType = Account::create([
        'code' => '101',
        'name' => 'Kas',
        'status' => 'D',
        'type' => 'Asset',
    ]);

    $this->bankAccountType = Account::create([
        'code' => '102',
        'name' => 'Bank',
        'status' => 'D',
        'type' => 'Asset',
    ]);

    $this->expenseAccountType = Account::create([
        'code' => '601',
        'name' => 'Beban Operasional',
        'status' => 'D',
        'type' => 'Expense',
    ]);

    $this->primaryCash = ChartOfAccount::create([
        'code' => '10100-001',
        'name' => 'Kas Utama HQ',
        'account_id' => $this->cashAccountType->id,
        'warehouse_id' => $this->warehouse->id,
        'is_primary_cash' => true,
        'st_balance' => 1000000,
    ]);

    $this->bankSource = ChartOfAccount::create([
        'code' => '10200-001',
        'name' => 'Bank BCA HQ',
        'account_id' => $this->bankAccountType->id,
        'warehouse_id' => $this->warehouse->id,
        'is_primary_cash' => false,
        'st_balance' => 5000000,
    ]);

    $this->bankDest = ChartOfAccount::create([
        'code' => '10200-002',
        'name' => 'Bank BRI HQ',
        'account_id' => $this->bankAccountType->id,
        'warehouse_id' => $this->warehouse->id,
        'is_primary_cash' => false,
        'st_balance' => 2000000,
    ]);

    $this->bankFeeCoa = ChartOfAccount::create([
        'code' => '60113-003',
        'name' => 'Beban Biaya Administrasi Bank',
        'account_id' => $this->expenseAccountType->id,
        'warehouse_id' => null,
        'is_primary_cash' => false,
        'st_balance' => 0,
    ]);

    $this->user = User::factory()->create([
        'warehouse_id' => $this->warehouse->id,
        'role' => 'Super Admin',
    ]);
});

test('mutasi kas with admin fee records admin fee to Beban Biaya Administrasi Bank instead of Kas Utama', function () {
    $this->actingAs($this->user);

    $response = $this->postJson('/api/create-mutation', [
        'debt_id' => $this->bankDest->id,
        'cred_id' => $this->bankSource->id,
        'amount' => 100000,
        'trx_type' => 'Mutasi Kas',
        'admin_fee' => 2500,
        'warehouse_id' => $this->warehouse->id,
        'description' => 'Transfer antar bank',
    ]);

    $response->assertStatus(201);

    // Verify Main Journal (Mutasi Kas)
    $this->assertDatabaseHas('journals', [
        'debt_id' => $this->bankDest->id,
        'cred_id' => $this->bankSource->id,
        'amount' => 100000,
        'trx_type' => 'Mutasi Kas',
    ]);

    // Verify Admin Fee Journal
    // CRITICAL: debt_id must be Beban Biaya Administrasi Bank, NOT Kas Utama
    $this->assertDatabaseHas('journals', [
        'debt_id' => $this->bankFeeCoa->id,
        'cred_id' => $this->bankSource->id,
        'amount' => 2500,
        'fee_amount' => -2500,
        'trx_type' => 'Pengeluaran',
        'description' => 'Biaya Administrasi Bank',
    ]);

    // Ensure Kas Utama is NOT debited
    $this->assertDatabaseMissing('journals', [
        'debt_id' => $this->primaryCash->id,
        'description' => 'Biaya Administrasi Bank',
    ]);

    // Verify Cash Flow for Admin Fee is positive and categorized properly
    $adminJournal = Journal::where('description', 'Biaya Administrasi Bank')->first();
    expect($adminJournal)->not->toBeNull();
    expect($adminJournal->debt_id)->toBe($this->bankFeeCoa->id);

    $this->assertDatabaseHas('cash_flows', [
        'journal_id' => $adminJournal->id,
        'amount' => 2500,
        'type' => 'expense',
        'category' => 'Biaya Administrasi Bank',
    ]);
});

test('pengeluaran with active_tab bankfee normalizes debt_id to Beban Biaya Administrasi Bank', function () {
    $this->actingAs($this->user);

    // Frontend sends primaryCash id as dummy debt_id when active_tab is bankfee
    $response = $this->postJson('/api/create-mutation', [
        'debt_id' => $this->primaryCash->id,
        'cred_id' => $this->bankSource->id,
        'amount' => 2500,
        'fee_amount' => -2500,
        'trx_type' => 'Pengeluaran',
        'description' => 'Biaya Administrasi Bank',
        'active_tab' => 'bankfee',
        'warehouse_id' => $this->warehouse->id,
    ]);

    $response->assertStatus(201);

    // Verify Journal debt_id is normalized to Beban Biaya Administrasi Bank
    $this->assertDatabaseHas('journals', [
        'debt_id' => $this->bankFeeCoa->id,
        'cred_id' => $this->bankSource->id,
        'amount' => 2500,
        'fee_amount' => -2500,
        'trx_type' => 'Pengeluaran',
    ]);

    // Ensure Kas Utama is NOT debited
    $this->assertDatabaseMissing('journals', [
        'debt_id' => $this->primaryCash->id,
        'trx_type' => 'Pengeluaran',
    ]);

    // Verify Cash Flow has correct category and positive amount
    $this->assertDatabaseHas('cash_flows', [
        'amount' => 2500,
        'type' => 'expense',
        'category' => 'Biaya Administrasi Bank',
    ]);
});

test('mutasi kas without admin fee does not create secondary journal or cashflow', function () {
    $this->actingAs($this->user);

    $response = $this->postJson('/api/create-mutation', [
        'debt_id' => $this->bankDest->id,
        'cred_id' => $this->bankSource->id,
        'amount' => 50000,
        'trx_type' => 'Mutasi Kas',
        'admin_fee' => 0,
        'warehouse_id' => $this->warehouse->id,
        'description' => 'Mutasi Kas Tanpa Biaya',
    ]);

    $response->assertStatus(201);
    expect(Journal::count())->toBe(1);
    expect(CashFlow::count())->toBe(0);
});

test('mutasi kas rejects 0 amount', function () {
    $this->actingAs($this->user);

    $response = $this->postJson('/api/create-mutation', [
        'debt_id' => $this->bankDest->id,
        'cred_id' => $this->bankSource->id,
        'amount' => 0,
        'trx_type' => 'Mutasi Kas',
        'warehouse_id' => $this->warehouse->id,
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Jumlah mutasi kas tidak boleh 0.',
        ]);
});

test('backdated mutasi kas with admin fee updates account balances', function () {
    $this->actingAs($this->user);

    $backdate = Carbon::yesterday()->format('Y-m-d H:i:s');

    $response = $this->postJson('/api/create-mutation', [
        'date_issued' => $backdate,
        'debt_id' => $this->bankDest->id,
        'cred_id' => $this->bankSource->id,
        'amount' => 100000,
        'trx_type' => 'Mutasi Kas',
        'admin_fee' => 2500,
        'warehouse_id' => $this->warehouse->id,
        'description' => 'Backdate mutation',
    ]);

    $response->assertStatus(201);

    // Verify account balances record was created for yesterday
    $this->assertDatabaseHas('account_balances', [
        'chart_of_account_id' => $this->bankSource->id,
        'balance_date' => Carbon::yesterday()->toDateString(),
    ]);
});
