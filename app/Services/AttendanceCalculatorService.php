<?php

namespace App\Services;

use Carbon\Carbon;

class AttendanceCalculatorService
{
    /**
     * Menghitung total net selisih menit absensi.
     * Lebih awal  = + (positif)
     * Terlambat   = - (negatif)
     */
    public function calculateTimeDiffMinutes($attendances): array
    {
        $totalMinutes = 0;
        $totalLateDays = 0;
        $totalEarlyDays = 0;

        foreach ($attendances as $att) {
            // Pastikan ada time_in dan target jam buka/masuk
            $targetTime = $att->target_opening_time ?? $att->work_start;
            $actualTime = $att->time_in;

            if (!$actualTime || !$targetTime) {
                continue;
            }

            // Carbon diffInMinutes(target, false) -> menghasilkan minus jika telat
            $target = Carbon::parse($targetTime);
            $actual = Carbon::parse($actualTime);

            $diffInMinutes = $actual->diffInMinutes($target, false);
            $totalMinutes += $diffInMinutes;

            if ($diffInMinutes < 0) {
                $totalLateDays++;
            } elseif ($diffInMinutes > 0) {
                $totalEarlyDays++;
            }
        }

        return [
            'total_net_minutes' => $totalMinutes,
            'total_late_days'   => $totalLateDays,
            'total_early_days'  => $totalEarlyDays,
            'formatted_text'    => $this->formatMinutesToHuman($totalMinutes),
        ];
    }

    public function formatMinutesToHuman(int $totalMinutes): string
    {
        $isNegative = $totalMinutes < 0;
        $absMinutes = abs($totalMinutes);

        $hours = floor($absMinutes / 60);
        $minutes = $absMinutes % 60;

        $text = "";
        if ($hours > 0) {
            $text .= "{$hours} jam ";
        }
        $text .= "{$minutes} menit";

        if ($isNegative) {
            return "Net Telat: -{$text}";
        }

        return "Net Awal: +{$text}";
    }
}
