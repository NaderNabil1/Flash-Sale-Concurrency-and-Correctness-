<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\Models\Product;
use App\Models\Order;
use App\Models\Hold;
use App\Models\PaymentWebhook;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'idempotency_key' => 'required|string',
            'order_id'        => 'required|integer|exists:orders,id',
            'status'          => 'required|in:success,failure',
        ], [
            'idempotency_key.required' => 'Idempotency key is required.',
            'idempotency_key.string'   => 'Idempotency key must be a string.',

            'order_id.required' => 'Order ID is required.',
            'order_id.integer'  => 'Order ID must be a valid integer.',
            'order_id.exists'   => 'The selected Order ID does not exist.',

            'status.required' => 'Status is required.',
            'status.in'       => 'Status must be either "success" or "failure".',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        try {
            $result = DB::transaction(function () use ($data, $request) {
                $webhook = PaymentWebhook::where('idempotency_key', $data['idempotency_key'])->first();

                if ($webhook) {
                    if ($webhook->order_id != $data['order_id']) {
                        throw new \Exception('Idempotency key already used with different order_id');
                    }
                    $order = Order::findOrFail($webhook->order_id);

                    return [$order, $webhook, false];
                }

                $order = Order::where('id', $data['order_id'])->lockForUpdate()->firstOrFail();

                $webhook = PaymentWebhook::create([
                    'idempotency_key' => $data['idempotency_key'],
                    'order_id'        => $order->id,
                    'result'          => $data['status'],
                    'payload'         => $request->all(),
                    'processed_at'    => now(),
                ]);

                if ($data['status'] === 'success') {
                    if ($order->status !== 'paid') {
                        $order->status = 'paid';
                        $order->save();
                    }
                } else {
                    if ($order->status === 'pending') {
                        $order->status = 'cancelled';
                        $order->save();

                        $hold = Hold::where('id', $order->hold_id)->lockForUpdate()->first();

                        if ($hold && ! in_array($hold->status, ['expired', 'cancelled'])) {
                            $product = Product::where('id', $hold->product_id)
                                ->lockForUpdate()
                                ->first();

                            $product->available_stock += $hold->qty;
                            $product->save();

                            $hold->status = 'cancelled';
                            $hold->save();
                        }
                    }
                }

                return [$order, $webhook, true];
            });
        } catch (\Throwable $e) {
            Log::error('Payment webhook failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all(),
            ]);
            return response()->json(['error' => 'webhook failed'], 500);
        }

        [$order, $webhook, $isFirstTime] = $result;

        Log::info('Payment webhook handled', [
            'idempotency_key' => $webhook->idempotency_key,
            'order_id' => $order->id,
            'result' => $webhook->result,
            'first_time' => $isFirstTime,
        ]);

        return response()->json([
            'order_id' => $order->id,
            'order_status' => $order->status,
            'idempotency_key' => $webhook->idempotency_key,
        ]);
    }
}
