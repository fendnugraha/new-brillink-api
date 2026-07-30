<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'amount', 'type', 'employee_id'])]
class SalaryComponent extends Model
{
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
