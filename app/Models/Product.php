<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'total_stock',
        'available_stock',
        'price_cents',
    ];
}
