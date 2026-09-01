<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('accounting:update-balances')->dailyAt('23:59');
Schedule::command('warnings:expire')->daily();

Artisan::command('import:deliveries', function () {
    $journals = DB::table('journals')
        ->where('trx_type', 'Mutasi Kas')
        ->where('cred_id', 2)
        ->get();

    $insertData = [];
    foreach ($journals as $j) {
        $insertData[] = [
            'id'                     => (string) Str::uuid(),
            'journal_id'             => $j->id,
            'source_account_id'      => 2,
            'destination_account_id' => $j->debt_id,
            'courier_id'             => 15,
            'received_by_id'         => 15,
            'received_at'            => $j->date_issued,
            'priority'               => 'low',
            'status'                 => 'delivered',
            'created_at'             => $j->created_at,
            'updated_at'             => $j->updated_at,
        ];
    }

    // Insert sekaligus dalam potongan per 500 baris
    foreach (array_chunk($insertData, 500) as $batch) {
        DB::table('deliveries')->insert($batch);
    }

    $this->info('Import data deliveries berhasil!');
});
