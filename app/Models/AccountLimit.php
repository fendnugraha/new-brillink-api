<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountLimit extends Model
{
    protected $guarded = ['id'];

    public function chartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class);
    }
}
