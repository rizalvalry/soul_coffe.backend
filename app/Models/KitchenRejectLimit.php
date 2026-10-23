<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenRejectLimit extends Model
{
    protected $fillable = ['kitchen_id', 'product_id', 'daily_limit'];
}
