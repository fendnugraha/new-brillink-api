<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    public function ChartOfAccount()
    {
        return $this->hasMany(ChartOfAccount::class);
    }
}
