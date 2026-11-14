<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ChartOfAccount extends Model
{
    const SAVING_ACCOUNT = 160;

    const EMPLOYEE_RECEIVABLE = 8;

    const BANK_ADMIN_FEE = 46;

    protected $guarded = ['id'];

    public static function getBankAdminFeeAccount(): ?self
    {
        return self::where('name', 'Beban Biaya Administrasi Bank')
            ->orWhere('code', '60113-003')
            ->orWhere('name', 'like', '%Biaya Administrasi Bank%')
            ->orWhere('id', self::BANK_ADMIN_FEE)
            ->orderByRaw("CASE
                WHEN name = 'Beban Biaya Administrasi Bank' THEN 1
                WHEN code = '60113-003' THEN 2
                WHEN name LIKE '%Biaya Administrasi Bank%' THEN 3
                ELSE 4
            END")
            ->first();
    }

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'account_id' => 'integer',
        'warehouse_id' => 'integer',
        'st_balance' => 'integer',
    ];

    public function debt()
    {
        return $this->hasMany(Journal::class, 'debt_id', 'id');
    }

    public function cred()
    {
        return $this->hasMany(Journal::class, 'cred_id', 'id');
    }

    public function code($account_id)
    {
        $accounts = Account::find($account_id);

        $lastCode = DB::table('chart_of_accounts')
            ->select(DB::raw('MAX(RIGHT(code,3)) AS lastCode'))
            ->where('account_id', $account_id)
            ->get();

        if ($lastCode[0]->lastCode != null) {
            $kd = $lastCode[0]->lastCode + 1;
        } else {
            $kd = '001';
        }

        return $accounts->code.'-'.\sprintf('%03s', $kd);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function limit()
    {
        return $this->hasOne(AccountLimit::class);
    }
}
