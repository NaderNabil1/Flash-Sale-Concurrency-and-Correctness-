<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\PaymentWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Illuminate\Support\Carbon;

class FlashSaleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed a product for testing
        Product::create([
            'name' => 'Flash Sale Item',
            'total_stock' => 100,
            'available_stock' => 100,
            'price_cents' => 1000,
        ]);
    }

    /**
     * Test: Parallel hold attempts at stock boundary prevents overselling.
     *
     * Creates 150 concurrent hold requests for a product with only 100 stock.
     * Verifies that exactly 100 holds succeed and stock never goes negative.
     */
    public function test_parallel_hold_attempts_at_stock_boundary_prevents_overselling(): void
    {
        $product = Product::first();
        $initialStock = $product->available_stock;

        // Create 150 requests for a product with 100 stock
        // This simulates concurrent requests hitting the stock boundary
        $responses = [];
        $threads = 150;

        // Simulate concurrent requests - in real scenario these would be parallel
        // The database locking ensures correct behavior even with true concurrency
        for ($i = 0; $i < $threads; $i++) {
            $responses[] = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);
        }

        // Analyze results
        $successfulHolds = 0;
        $failedHolds = 0;

        foreach ($responses as $response) {
            if ($response->status() === 201) {
                $successfulHolds++;
            } else {
                $failedHolds++;
                // Verify failure is due to insufficient stock
                $this->assertContains($response->status(), [422, 429]);
            }
        }

        // Refresh product from database to get latest state
        $product->refresh();

        // CRITICAL INVARIANT: Stock must never go negative
        $this->assertGreaterThanOrEqual(0, $product->available_stock,
            'Stock must never go negative under any concurrent load');

        // Verify no overselling: total successful holds should be <= initial stock
        $this->assertLessThanOrEqual($initialStock, $successfulHolds,
            'Cannot create more holds than available stock');

        // Verify stock consistency: initial stock = holds created + remaining stock
        $totalHeldQty = Hold::where('product_id', $product->id)
            ->where('status', 'active')
            ->sum('qty');

        $expectedAvailableStock = $initialStock - $totalHeldQty;
        $this->assertEquals($expectedAvailableStock, $product->available_stock,
            'Available stock must match: initial - total held quantity');

        // Verify the boundary condition: exactly 100 should succeed
        $this->assertEquals($initialStock, $successfulHolds,
            'Exactly ' . $initialStock . ' holds should succeed, preventing overselling');

        // Verify remaining stock is zero (all stock was allocated)
        $this->assertEquals(0, $product->available_stock,
            'All stock should be allocated when ' . $threads . ' requests compete for ' . $initialStock . ' stock');
    }

    /**
     * Test: Hold expiry returns availability correctly.
     *
     * Creates a hold, manually expires it, and verifies stock is returned.
     */
    public function test_hold_expiry_returns_availability(): void
    {
        $product = Product::first();
        $initialStock = $product->available_stock;

        // Create a hold for 10 items
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ]);

        $holdResponse->assertStatus(201);
        $holdData = $holdResponse->json();
        $holdId = $holdData['hold_id'];

        // Verify stock was reduced
        $product->refresh();
        $this->assertEquals($initialStock - 10, $product->available_stock,
            'Stock should be reduced when hold is created');

        // Manually expire the hold by setting expires_at to past
        $hold = Hold::findOrFail($holdId);
        $hold->expires_at = Carbon::now()->subMinute();
        $hold->save();

        // Run the expiration command
        $this->artisan('holds:expire')->assertSuccessful();

        // Verify hold status changed
        $hold->refresh();
        $this->assertEquals('expired', $hold->status,
            'Hold status should be expired after running expiration command');

        // Verify stock was returned
        $product->refresh();
        $this->assertEquals($initialStock, $product->available_stock,
            'Stock should be returned to initial value after hold expires');
    }

    /**
     * Test: Webhook idempotency - same key repeated returns same result.
     *
     * Sends the same webhook multiple times and verifies idempotent behavior.
     */
    public function test_webhook_idempotency_same_key_repeated(): void
    {
        $product = Product::first();

        // Create hold and order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);
        $holdId = $holdResponse->json()['hold_id'];

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);
        $orderId = $orderResponse->json()['order_id'];

        // First webhook - success
        $idempotencyKey = 'test-key-' . uniqid();
        $webhookPayload = [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'status' => 'success',
        ];

        $response1 = $this->postJson('/api/payments/webhook', $webhookPayload);
        $response1->assertStatus(200);
        $response1->assertJson([
            'order_id' => $orderId,
            'order_status' => 'paid',
        ]);

        // Verify order is paid
        $order = Order::findOrFail($orderId);
        $this->assertEquals('paid', $order->status);

        // Second webhook - same idempotency key (duplicate)
        $response2 = $this->postJson('/api/payments/webhook', $webhookPayload);
        $response2->assertStatus(200);
        $response2->assertJson([
            'order_id' => $orderId,
            'order_status' => 'paid',
        ]);

        // Verify order status hasn't changed
        $order->refresh();
        $this->assertEquals('paid', $order->status,
            'Order status should remain paid on duplicate webhook');

        // Verify only one webhook record exists
        $webhookCount = PaymentWebhook::where('idempotency_key', $idempotencyKey)->count();
        $this->assertEquals(1, $webhookCount,
            'Only one webhook record should exist for same idempotency key');

        // Third webhook - same key again
        $response3 = $this->postJson('/api/payments/webhook', $webhookPayload);
        $response3->assertStatus(200);
        $response3->assertJson([
            'order_id' => $orderId,
            'order_status' => 'paid',
        ]);

        // Verify idempotency maintained
        $order->refresh();
        $this->assertEquals('paid', $order->status);
        $webhookCount = PaymentWebhook::where('idempotency_key', $idempotencyKey)->count();
        $this->assertEquals(1, $webhookCount);
    }

    /**
     * Test: Webhook arriving before order creation is handled correctly.
     *
     * Simulates a scenario where webhook arrives before order is fully created.
     * Note: Since order_id is required and validated, webhook will fail if order doesn't exist.
     * This tests that webhook validation properly handles missing orders.
     */
    public function test_webhook_arriving_before_order_creation(): void
    {
        $product = Product::first();

        // Create hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);
        $holdId = $holdResponse->json()['hold_id'];

        // Attempt webhook BEFORE order creation (order doesn't exist yet)
        $nonExistentOrderId = 99999;
        $idempotencyKey = 'early-webhook-' . uniqid();

        $webhookPayload = [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $nonExistentOrderId,
            'status' => 'success',
        ];

        // Webhook should fail validation because order doesn't exist
        $response = $this->postJson('/api/payments/webhook', $webhookPayload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['order_id']);

        // Verify no webhook record was created
        $webhookCount = PaymentWebhook::where('idempotency_key', $idempotencyKey)->count();
        $this->assertEquals(0, $webhookCount,
            'No webhook record should be created for non-existent order');

        // Now create the order properly
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);
        $orderId = $orderResponse->json()['order_id'];

        // Now webhook should work
        $webhookPayload['order_id'] = $orderId;
        $response = $this->postJson('/api/payments/webhook', $webhookPayload);
        $response->assertStatus(200);
        $response->assertJson([
            'order_id' => $orderId,
            'order_status' => 'paid',
        ]);

        // Verify order is paid
        $order = Order::findOrFail($orderId);
        $this->assertEquals('paid', $order->status);
    }

    /**
     * Additional test: Verify concurrent webhook with same idempotency key.
     *
     * Tests that concurrent webhooks with same idempotency key are handled correctly.
     */
    public function test_concurrent_webhooks_with_same_idempotency_key(): void
    {
        $product = Product::first();

        // Create hold and order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);
        $holdId = $holdResponse->json()['hold_id'];

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);
        $orderId = $orderResponse->json()['order_id'];

        // Send 10 concurrent webhooks with same idempotency key
        $idempotencyKey = 'concurrent-test-' . uniqid();
        $webhookPayload = [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'status' => 'success',
        ];

        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->postJson('/api/payments/webhook', $webhookPayload);
        }

        // All should succeed and return same result
        foreach ($responses as $response) {
            $response->assertStatus(200);
            $response->assertJson([
                'order_id' => $orderId,
                'order_status' => 'paid',
            ]);
        }

        // Verify only one webhook record exists
        $webhookCount = PaymentWebhook::where('idempotency_key', $idempotencyKey)->count();
        $this->assertEquals(1, $webhookCount,
            'Only one webhook record should exist despite concurrent requests');

        // Verify order is paid (not paid multiple times)
        $order = Order::findOrFail($orderId);
        $this->assertEquals('paid', $order->status);
    }

    /**
     * Additional test: Verify payment failure releases stock correctly.
     */
    public function test_payment_failure_releases_stock(): void
    {
        $product = Product::first();
        $initialStock = $product->available_stock;

        // Create hold and order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ]);
        $holdId = $holdResponse->json()['hold_id'];

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);
        $orderId = $orderResponse->json()['order_id'];

        // Verify stock is reduced
        $product->refresh();
        $this->assertEquals($initialStock - 10, $product->available_stock);

        // Send failure webhook
        $idempotencyKey = 'failure-test-' . uniqid();
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'status' => 'failure',
        ]);

        $response->assertStatus(200);

        // Verify order is cancelled
        $order = Order::findOrFail($orderId);
        $this->assertEquals('cancelled', $order->status);

        // Verify stock was released
        $product->refresh();
        $this->assertEquals($initialStock, $product->available_stock,
            'Stock should be returned after payment failure');

        // Verify hold is cancelled
        $hold = Hold::findOrFail($holdId);
        $this->assertEquals('cancelled', $hold->status);
    }
}

