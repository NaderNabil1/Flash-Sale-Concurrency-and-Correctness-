<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Models\Product;
use App\Models\Order;
use App\Models\Hold;

class OrderController extends Controller
{
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'hold_id' => 'required|integer|exists:holds,id',
        ], [
            'hold_id.required' => 'Hold ID is required.',
            'hold_id.integer'  => 'Hold ID must be a valid integer.',
            'hold_id.exists'   => 'The selected Hold ID does not exist in the records.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        $order = DB::transaction(function () use ($data) {
            $hold = Hold::where('id', $data['hold_id'])->lockForUpdate()->firstOrFail();

            if ($hold->status !== 'active' || $hold->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'hold_id' => 'Hold is not valid',
                ]);
            }

            if (Order::where('hold_id', $hold->id)->exists()) {
                throw ValidationException::withMessages([
                    'hold_id' => 'Hold already used',
                ]);
            }

            $product = Product::findOrFail($hold->product_id);

            $order = Order::create([
                'hold_id'      => $hold->id,
                'product_id'   => $product->id,
                'qty'          => $hold->qty,
                'amount_cents' => $product->price_cents * $hold->qty,
                'status'       => 'pending',
            ]);

            $hold->status = 'used';
            $hold->save();

            return $order;
        });

        return response()->json([
            'order_id' => $order->id,
            'status'   => $order->status,
        ], 201);
    }
}
