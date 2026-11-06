<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['employee_id', 'date', 'end_date', 'type', 'title', 'status', 'approved_by'])]
class EmployeeSchedule extends Model
{
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'employee_id', 'employee_id');
    }
}
