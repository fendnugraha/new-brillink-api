<?php

namespace App\Models;

use App\Models\ChartOfAccount;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Finance extends Model
{
    protected $guarded = ['id'];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id', 'id');
    }

    public function invoice_finance(int $contact_id, string $type)
    {
        $prefix = $type == 'Payable' ? 'PY' : 'RC';
        $lastInvoice = DB::table('finances')
            ->select(DB::raw('MAX(RIGHT(invoice,7)) AS kd_max'))
            ->where([
                ['contact_id', $contact_id],
            ])
            ->where('finance_type', $type)
            ->whereDate('created_at', date('Y-m-d'))
            ->get();

        $kd = "";
        if ($lastInvoice[0]->kd_max != null) {
            $tmp = ((int)$lastInvoice[0]->kd_max) + 1;
            $kd = sprintf("%07s", $tmp);
        } else {
            $kd = "0000001";
        }

        return $prefix . '.BK.' . date('dmY') . '.' . $contact_id . '.' . $kd;
    }

    public static function invoice_saving(int $contact_id)
    {
        $prefix = "SV";
        $lastInvoice = DB::table('finances')
            ->select(DB::raw('MAX(RIGHT(invoice,7)) AS kd_max'))
            ->where([
                ['contact_id', $contact_id],
            ])
            ->where('finance_type', 'Saving')
            ->whereDate('created_at', date('Y-m-d'))
            ->get();

        $kd = "";
        if ($lastInvoice[0]->kd_max != null) {
            $tmp = ((int)$lastInvoice[0]->kd_max) + 1;
            $kd = sprintf("%07s", $tmp);
        } else {
            $kd = "0000001";
        }

        return $prefix . '.BK.' . date('dmY') . '.' . $contact_id . '.' . $kd;
    }

    public static function payment_invoice(int $contact_id)
    {
        $prefix = 'PM.BK';
        $today = now();

        $lastInvoice = DB::table('finances')
            ->select(DB::raw('MAX(RIGHT(invoice,7)) as kd_max'))
            ->where('contact_id', $contact_id)
            ->whereDate('created_at', $today)
            ->first();

        $nextNumber = $lastInvoice?->kd_max
            ? str_pad(((int) $lastInvoice->kd_max) + 1, 7, '0', STR_PAD_LEFT)
            : '0000001';

        return "{$prefix}.{$today->format('dmY')}.{$contact_id}.{$nextNumber}";
    }

    public static function payment_invoice_by_payroll(int $contact_id)
    {
        $prefix = 'PR.BK';
        $today = now();

        $lastInvoice = DB::table('finances')
            ->select(DB::raw('MAX(RIGHT(invoice,7)) as kd_max'))
            ->where('contact_id', $contact_id)
            ->whereDate('created_at', $today)
            ->first();

        $nextNumber = $lastInvoice?->kd_max
            ? str_pad(((int) $lastInvoice->kd_max) + 1, 7, '0', STR_PAD_LEFT)
            : '0000001';

        return "{$prefix}.{$today->format('dmY')}.{$contact_id}.{$nextNumber}";
    }
}
