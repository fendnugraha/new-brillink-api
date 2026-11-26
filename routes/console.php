<?php

use App\Http\Controllers\AttendanceController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('accounting:update-balances')
    ->dailyAt('01:00')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/cron.log'));

Schedule::command('warnings:expire')->dailyAt('01:05');

Artisan::command('attendance:auto-excellent {date?}', function (?string $date = null) {
    $targetDate = $date ?: now()->toDateString();
    $result = AttendanceController::assignExcellentForDate($targetDate, true);
    $this->info("Auto Excellent ({$result['date']}): {$result['updated_count']} jalur diperbarui.");
})->purpose('Otomatis set status Excellent (Good) untuk staf paling awal di tiap jalur');

Schedule::command('attendance:auto-excellent')->dailyAt('23:50');

Schedule::call(function () {
    DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('read_at', '<', now()->subDays(30))
        ->delete();
})->dailyAt('01:10');

Artisan::command('import:deliveries', function () {
    $journals = DB::table('journals')
        ->where('trx_type', 'Mutasi Kas')
        ->where('cred_id', 2)
        ->get();

    $insertData = [];
    foreach ($journals as $j) {
        $insertData[] = [
            'id' => (string) Str::uuid(),
            'journal_id' => $j->id,
            'source_account_id' => 2,
            'destination_account_id' => $j->debt_id,
            'courier_id' => 15,
            'received_by_id' => 15,
            'received_at' => $j->date_issued,
            'priority' => 'low',
            'status' => 'delivered',
            'created_at' => $j->created_at,
            'updated_at' => $j->updated_at,
        ];
    }

    // Insert sekaligus dalam potongan per 500 baris
    foreach (array_chunk($insertData, 500) as $batch) {
        DB::table('deliveries')->insert($batch);
    }

    $this->info('Import data deliveries berhasil!');
});
