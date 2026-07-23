<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseZone extends Model
{
    protected $guarded = ['id'];

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }
}
