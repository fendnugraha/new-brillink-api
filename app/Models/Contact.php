<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Contact extends Model
{
    protected $guarded = ['id'];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function finances()
    {
        return $this->hasMany(Finance::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee_receivables()
    {
        return $this->hasMany(Finance::class)->where('finance_type', 'EmployeeReceivable');
    }

    public function employee_receivables_sum()
    {
        return $this->hasOne(Finance::class, 'contact_id')
            ->where('finance_type', 'EmployeeReceivable')
            ->select('contact_id', DB::raw('SUM(bill_amount - payment_amount) as total'))
            ->groupBy('contact_id');
    }

    public function installment_receivables_sum()
    {
        return $this->hasOne(Finance::class, 'contact_id')
            ->where('finance_type', 'InstallmentReceivable')
            ->select('contact_id', DB::raw('SUM(bill_amount - payment_amount) as total'))
            ->groupBy('contact_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'id', 'contact_id');
    }
}
