<?php

// app/Console/Commands/ExpireHolds.php
namespace App\Console\Commands;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireHolds extends Command
{
    protected $signature = 'holds:expire';
    protected $description = 'Release stock for expired holds';

    public function handle()
    {
        Hold::where('status', 'active')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(100, function ($holds) {
                foreach ($holds as $hold) {
                    DB::transaction(function () use ($hold) {
                        $hold = Hold::where('id', $hold->id)->lockForUpdate()->first();

                        if (! $hold || $hold->status !== 'active' || $hold->expires_at->isFuture()) {
                            return;
                        }

                        $product = Product::where('id', $hold->product_id)->lockForUpdate()->first();

                        if (! $product) {
                            Log::warning('Product not found for expired hold', [
                                'hold_id' => $hold->id,
                                'product_id' => $hold->product_id,
                            ]);
                            return;
                        }

                        $product->available_stock += $hold->qty;
                        $product->save();

                        $hold->status = 'expired';
                        $hold->save();

                        Log::info('Hold expired and stock released', [
                            'hold_id' => $hold->id,
                            'product_id' => $product->id,
                            'qty' => $hold->qty,
                        ]);
                    });
                }
            });

        return Command::SUCCESS;
    }
}
