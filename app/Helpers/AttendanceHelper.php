<?php

namespace App\Helpers;

use Carbon\Carbon;

class AttendanceHelper
{
    /**
     * Menghitung total selisih waktu dalam menit untuk kumpulan data absensi.
     * Lebih awal  = + (positif)
     * Terlambat   = - (negatif)
     */
    public static function calculateMonthlyTimeDiffMinutes($attendances): int
    {
        $totalMinutes = 0;

        foreach ($attendances as $att) {
            if (!$att->time_in || !$att->target_opening_time) {
                continue;
            }

            // Parse waktu menggunakan Carbon
            $target = Carbon::parse($att->target_opening_time);
            $actual = Carbon::parse($att->time_in);

            // diffInMinutes(target, false) -> menghasilkan minus jika actual > target (telat)
            // Carbon::parse('08:00:00')->diffInMinutes('08:15:00', false) => -15
            // Carbon::parse('08:00:00')->diffInMinutes('07:50:00', false) => +10
            $diffInMinutes = $actual->diffInMinutes($target, false);

            $totalMinutes += $diffInMinutes;
        }

        return $totalMinutes;
    }
}
