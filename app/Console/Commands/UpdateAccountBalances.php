<?php

namespace App\Console\Commands;

use App\Models\Journal;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('accounting:update-balances {--date=} {--company=1}')]
#[Description('Update account balances for a specific date')]
class UpdateAccountBalances extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Kita menggunakan kemarin agar data hari sebelumnya selesai diposting.
        $dateToUpdate = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday();
        $targetDate = $dateToUpdate->toDateString();

        $this->info("Updating account balances on date: {$targetDate}...");
        Log::info("Starting account balances update for date: {$targetDate}");

        try {
            Journal::_updateBalancesDirectly($targetDate);

            $this->info("Account balances update completed for {$targetDate}.");
            Log::info("Account balances update completed successfully for date: {$targetDate}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("An error occurred during balance update: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
