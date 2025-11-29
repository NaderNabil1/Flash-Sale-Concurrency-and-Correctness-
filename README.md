# Flash Sale Checkout API

This is a Laravel API I built to handle flash sales with limited stock. The main challenge here is preventing overselling when you've got hundreds of people trying to buy the same product at once. It uses temporary holds, handles orders, and processes payment webhooks in a way that won't break if the same webhook comes through twice.

## How It Works

I made a few key decisions when building this:

**The Basics:**
- One product per flash sale (keeps things simple)
- Holds expire after 2 minutes - gives people time to checkout but not forever
- Orders are created right away after validating the hold
- Payment webhooks come from an external provider and include idempotency keys (because webhooks can arrive multiple times)
- Using MySQL with InnoDB and row-level locking to handle concurrency
- Webhooks might arrive more than once, so everything needs to be idempotent

**What I'm Making Sure Never Happens:**

1. **Never oversell stock** - `available_stock` can never go below zero
   - I'm using database transactions with `lockForUpdate()` to make sure stock checks and decrements happen atomically
   - This prevents the classic race condition where two requests both see stock available and both try to claim it

2. **Each hold can only create one order** - prevents double-ordering
   - There's a unique constraint on `orders.hold_id` in the database
   - When an order is created, the hold status changes from `active` to `used`

3. **Expired holds release stock automatically**
   - There's a background job that runs every minute to clean up expired holds
   - Uses row-level locking so it won't process the same hold twice if it runs multiple times

4. **Webhooks are idempotent** - calling the same webhook twice gives the same result
   - Unique constraint on `payment_webhooks.idempotency_key` handles this
   - If we've seen this webhook before, we just return the stored result without doing anything

5. **Order statuses only move forward** - once paid or cancelled, that's it
   - `pending` → `paid` (when payment succeeds)
   - `pending` → `cancelled` (when payment fails)
   - Can't go backwards or change final states

6. **Stock math always adds up** - `available_stock = total_stock - (active_holds_qty + paid_orders_qty)`
   - This is maintained through atomic database operations
   - Stock gets returned when holds expire or payments fail

## API Endpoints

### `GET /api/products/{id}`
Gets product info including how much stock is available right now.

**Response:**
```json
{
  "id": 1,
  "name": "Flash Sale Item",
  "price_cents": 1000,
  "available_stock": 95
}
```

### `POST /api/holds`
Creates a temporary hold on some stock. This expires in 2 minutes if you don't convert it to an order.

**Request:**
```json
{
  "product_id": 1,
  "qty": 5
}
```

**Response:**
```json
{
  "hold_id": 123,
  "expires_at": "2025-01-27T12:32:00+00:00"
}
```

### `POST /api/orders`
Takes a valid hold and creates an order from it.

**Request:**
```json
{
  "hold_id": 123
}
```

**Response:**
```json
{
  "order_id": 456,
  "status": "pending"
}
```

### `POST /api/payments/webhook`
This is where the payment provider sends webhooks. It's idempotent, so if the same webhook comes through multiple times, you'll get the same result.

**Request:**
```json
{
  "idempotency_key": "webhook-key-123",
  "order_id": 456,
  "status": "success"
}
```

**Response:**
```json
{
  "order_id": 456,
  "order_status": "paid",
  "idempotency_key": "webhook-key-123"
}
```

## Getting Started

### Setup

1. **Install dependencies:**
   ```bash
   composer install
   npm install
   ```

2. **Set up your environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Configure your database in `.env`:**
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=flash_sale
   DB_USERNAME=root
   DB_PASSWORD=
   ```
   (Obviously change these to match your setup)

4. **Run migrations and seed some test data:**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

5. **Start everything up:**
   ```bash
   composer run dev
   ```

   This fires up:
   - The web server at http://localhost:8000
   - Queue worker
   - Task scheduler (for expiring holds)
   - Vite dev server

### Running Tests

To run all tests:
```bash
php artisan test
```

Or run just the concurrency test:
```bash
php artisan test tests/Feature/FlashSaleConcurrencyTest.php
```

### What the Tests Cover

The test suite (`tests/Feature/FlashSaleConcurrencyTest.php`) checks:

1. **Concurrent requests at stock limit** - 150 people trying to buy when there's only 100 items → exactly 100 succeed, no overselling
2. **Hold expiration** - expired holds properly release stock back
3. **Webhook idempotency** - same webhook key always gives same result
4. **Webhook edge cases** - what happens if a webhook arrives before the order exists
5. **Payment failures** - failed payments return stock and cancel orders

### Deploying to Production

1. **Set up a cron job** to run the scheduler every minute:
   ```bash
   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
   ```

2. **Run a queue worker** (if you're using queues):
   ```bash
   php artisan queue:work --daemon
   ```

3. **Make sure your `.env` is set for production:**
   ```env
   APP_ENV=production
   APP_DEBUG=false
   ```

## Logs and Monitoring

### Where Logs Live

All logs go to:
```
storage/logs/laravel.log
```

### What Gets Logged

I've added structured logging for the important events:

**When a hold is created:**
```php
Log::info('Hold created', [
    'hold_id' => $hold->id,
    'product_id' => $hold->product_id,
    'qty' => $hold->qty,
]);
```

**When a hold expires:**
```php
Log::info('Hold expired and stock released', [
    'hold_id' => $hold->id,
    'product_id' => $product->id,
    'qty' => $hold->qty,
]);
```

**Payment webhooks:**
```php
Log::info('Payment webhook handled', [
    'idempotency_key' => $webhook->idempotency_key,
    'order_id' => $order->id,
    'result' => $webhook->result,
    'first_time' => $isFirstTime,
]);
```

**Errors:**
```php
Log::error('Payment webhook failed', [
    'error' => $e->getMessage(),
    'payload' => $request->all(),
]);
```

### Viewing Logs

**Watch logs in real-time:**
```bash
tail -f storage/logs/laravel.log
```

**Search for specific stuff:**
```bash
grep "Hold expired" storage/logs/laravel.log
grep "Payment webhook" storage/logs/laravel.log
```

### Things to Keep an Eye On

In production, you'll want to monitor:

1. **Hold creation rate** - how many requests per second
2. **Hold success/failure rate** - what percentage actually succeed
3. **Expired holds** - how many are expiring per minute
4. **Webhook processing time** - how long it takes to process payments
5. **Stock levels** - track `available_stock` over time
6. **Order statuses** - how many pending vs paid vs cancelled
7. **Deadlocks** - database deadlock errors (should be pretty rare with this setup)

## Technical Details

### How Concurrency Works

I'm using MySQL's `SELECT ... FOR UPDATE` to lock rows during stock operations. This prevents race conditions where multiple requests try to claim the same stock at the same time. All stock changes happen inside database transactions, so either everything succeeds or nothing does.

For hold expiration, I process them in batches of 100 to keep things efficient, and use `withoutOverlapping()` to make sure the job doesn't run multiple times simultaneously.

### Caching

Product info like name and price gets cached for 60 seconds since that doesn't change often. But available stock is always fetched fresh from the database - never cached. The slight performance hit is worth it to make sure we never show stale stock numbers.

### Background Jobs

The hold expiration job runs every minute via Laravel's scheduler. It processes expired holds in chunks of 100 and uses row-level locking to prevent double-processing. The `withoutOverlapping()` method ensures it won't start a new run if the previous one is still going.
