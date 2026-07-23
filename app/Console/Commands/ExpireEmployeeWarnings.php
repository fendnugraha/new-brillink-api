<?php

namespace App\Console\Commands;

use App\Models\EmployeeWarning;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('warnings:expire')]
#[Description('Auto expire employee warnings')]
class ExpireEmployeeWarnings extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $expired = EmployeeWarning::where('is_active', true)
            ->whereNotNull('expired_date')
            ->whereDate('expired_date', '<', now())
            ->update(['is_active' => false]);

        $this->info("Expired warnings: {$expired}");
    }
}
