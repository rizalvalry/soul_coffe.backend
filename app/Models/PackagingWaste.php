<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingWaste extends Model
{
    protected $fillable = ['request_key', 'payload_hash', 'operating_date', 'kitchen_id', 'raw_material_id',
        'qty', 'cost_minor', 'stock_ledger_id', 'note', 'actor_id'];
}
