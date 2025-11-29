<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Product;

class ProductController extends Controller
{
    public function show($id)
    {
        $product = Cache::remember("product:{$id}:info", 60, function () use ($id) {
            return Product::select('id', 'name', 'price_cents')->findOrFail($id);
        });

        $freshStock = Product::where('id', $id)->value('available_stock');

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'price_cents' => $product->price_cents,
            'available_stock' => $freshStock,
        ]);
    }
}
