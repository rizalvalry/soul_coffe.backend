<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionalUse extends Model
{
    protected $fillable = ['request_key', 'payload_hash', 'kitchen_id', 'product_id', 'qty', 'note', 'actor_id', 'stock_ledger_id'];
}
