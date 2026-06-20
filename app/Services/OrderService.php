<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Transaction Integrity (ACID) + Concurrent Data Integrity.
 *
 * placeOrder() is a composite operation — payment + stock update + order
 * creation — that must be ALL-or-NOTHING, even under concurrent access:
 *
 *  - Atomicity & Consistency: the whole body runs inside DB::transaction(). If
 *    any step throws (out of stock, payment declined, DB error) every change is
 *    rolled back: no stock consumed, no order, no payment recorded.
 *
 *  - Isolation: each product row is taken with lockForUpdate() (SELECT ... FOR
 *    UPDATE) so concurrent checkouts of the same item are serialised and stock
 *    can never go negative (no oversell).
 *
 *  - Deadlock safety under concurrency: products are locked in a deterministic
 *    order (sorted by id) so two concurrent orders touching the same items can
 *    never form a lock cycle. As a backstop, the transaction is retried up to
 *    MAX_DEADLOCK_RETRIES times if the database still reports a deadlock /
 *    serialization failure.
 */
class OrderService
{
    /** Re-run the transaction this many times on a deadlock/serialization abort. */
    private const MAX_DEADLOCK_RETRIES = 3;

    public function __construct(
        private readonly ProductCacheService $cache,
        private readonly PaymentService $payment,
    ) {
    }

    public function placeOrder(int $userId, ?string $notes = null): Order
    {
        $order = DB::transaction(function () use ($userId, $notes) {
            $cart = Cart::with('items.product')->where('user_id', $userId)->firstOrFail();

            if ($cart->items->isEmpty()) {
                throw new \RuntimeException('Cart is empty.');
            }

            // Lock products in a deterministic order (by id) to avoid deadlocks
            // when concurrent orders contain overlapping products.
            $cartItems = $cart->items->sortBy('product_id')->values();

            $total = 0;
            $orderItemsData = [];
            $lockedProducts = [];

            foreach ($cartItems as $cartItem) {
                /*
                 * lockForUpdate() — exclusive row-level lock held until commit.
                 * Validate availability while holding the lock so the stock we
                 * commit to is the stock we checked.
                 */
                $product = Product::where('id', $cartItem->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $product->is_active) {
                    throw new \RuntimeException("Product [{$product->name}] is no longer available.");
                }

                if ($product->stock_quantity < $cartItem->quantity) {
                    throw new \RuntimeException(
                        "Insufficient stock for [{$product->name}]. Available: {$product->stock_quantity}, Requested: {$cartItem->quantity}."
                    );
                }

                $total += $cartItem->quantity * $product->price;

                $orderItemsData[] = [
                    'product_id' => $product->id,
                    'quantity'   => $cartItem->quantity,
                    'unit_price' => $product->price,
                ];

                $lockedProducts[] = [$product, $cartItem->quantity];
            }

            /*
             * Payment is part of the atomic unit: a decline throws and the
             * transaction rolls back, so the stock decrements below never happen
             * and no order row survives. Stock is already validated & locked, so
             * we only commit it after the charge is authorised.
             */
            $reference = $this->payment->charge($userId, (float) $total);

            foreach ($lockedProducts as [$product, $quantity]) {
                $product->decrement('stock_quantity', $quantity);
                // Bump the optimistic version so admin "adjust" requests holding
                // an older version are rejected after a sale changes the stock.
                $product->increment('lock_version');
            }

            $order = Order::create([
                'user_id'           => $userId,
                'status'            => 'processing',
                'payment_status'    => 'paid',
                'payment_reference' => $reference,
                'total_amount'      => $total,
                'notes'             => $notes,
            ]);

            $order->items()->createMany($orderItemsData);

            $cart->items()->delete();

            return $order;
        }, self::MAX_DEADLOCK_RETRIES);

        // Stock changed → invalidate the product cache so reads don't serve stale stock.
        $this->cache->flush();

        return $order;
    }
}
