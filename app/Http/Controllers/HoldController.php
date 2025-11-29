<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\Models\Product;
use App\Models\Hold;
use App\Http\Requests\CreateHoldRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class HoldController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'qty'        => 'required|integer|min:1',
        ], [
            'product_id.required' => 'Product is required.',
            'product_id.exists'   => 'The selected product does not exist.',
            'qty.required'        => 'Quantity is required.',
            'qty.integer'         => 'Quantity must be a valid number.',
            'qty.min'             => 'Quantity must be at least 1.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        $now = now();
        $expiresAt = $now->copy()->addMinutes(2);

        $hold = DB::transaction(function () use ($data, $expiresAt) {
            $product = Product::where('id', $data['product_id'])->lockForUpdate()->firstOrFail();

            if ($product->available_stock < $data['qty']) {
                throw ValidationException::withMessages([
                    'qty' => 'Not enough stock',
                ]);
            }

            $product->available_stock -= $data['qty'];
            $product->save();

            return Hold::create([
                'product_id' => $product->id,
                'qty'        => $data['qty'],
                'status'     => 'active',
                'expires_at' => $expiresAt,
            ]);
        });

        Log::info('Hold created', [
            'hold_id' => $hold->id,
            'product_id' => $hold->product_id,
            'qty' => $hold->qty,
        ]);

        return response()->json([
            'hold_id' => $hold->id,
            'expires_at' => $hold->expires_at->format('Y-m-d H:i:s'),
        ], 201);
    }
}
