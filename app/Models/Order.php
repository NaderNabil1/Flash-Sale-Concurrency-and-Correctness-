<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'hold_id',
        'product_id',
        'qty',
        'amount_cents',
        'status',
    ];

    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
