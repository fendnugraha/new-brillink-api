<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable('letter_number', 'employee_id', 'issued_by', 'level', 'issued_date', 'expired_date', 'reason', 'attachment_path', 'acknowledged_at', 'is_active')]
class EmployeeWarning extends Model
{
    use SoftDeletes;

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            // 1. Generate nomor surat jika belum diisi manual
            if (empty($model->letter_number)) {
                $model->letter_number = static::generateLetterNumber(
                    $model->level,
                    $model->issued_date
                );
            }

            // 2. Set expired_date otomatis +6 bulan jika belum diisi
            if (empty($model->expired_date) && !empty($model->issued_date)) {
                $model->expired_date = Carbon::parse($model->issued_date)->addMonths(3);
            }
        });
    }

    /**
     * Helper Function: Generate Nomor Surat Peringatan
     * Format Output: 001/TK-SP1/VIII/2026
     */
    public static function generateLetterNumber(string $level, ?string $issuedDate = null): string
    {
        $date = $issuedDate ? Carbon::parse($issuedDate) : now();
        $year = $date->year;
        $month = $date->month;

        // Map Bulan ke Angka Romawi
        $romanMonths = [
            1 => 'I',
            2 => 'II',
            3 => 'III',
            4 => 'IV',
            5 => 'V',
            6 => 'VI',
            7 => 'VII',
            8 => 'VIII',
            9 => 'IX',
            10 => 'X',
            11 => 'XI',
            12 => 'XII'
        ];
        $romanMonth = $romanMonths[$month] ?? 'I';

        // Hitung jumlah SP yang terbit di bulan & tahun yang sama (termasuk yang di-softdelete)
        $count = static::withTrashed()
            ->whereYear('issued_date', $year)
            ->whereMonth('issued_date', $month)
            ->count();

        // Nomor urut 3 digit (misal: 1 -> 001)
        $sequence = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        // Contoh hasil: 001/TK-SP1/VIII/2026
        return "{$sequence}/TK-{$level}/{$romanMonth}/{$year}";
    }
}
