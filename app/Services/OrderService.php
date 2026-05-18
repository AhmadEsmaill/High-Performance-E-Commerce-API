<?php

namespace App\Services;

use App\Jobs\GenerateInvoiceJob;
use App\Jobs\SendOrderNotificationJob;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Non-Functional Requirement #1: Concurrent Data Integrity (Race Condition Prevention)
 *
 * Problem: Without locking, two users purchasing the last item simultaneously
 * both read stock=1, both pass the check, and both decrement → stock becomes -1 (oversell).
 *
 * Solution: DB::transaction() + lockForUpdate() (Pessimistic Locking)
 * - lockForUpdate() issues SELECT ... FOR UPDATE which blocks other transactions
 *   from reading or modifying the row until the current transaction commits.
 * - This guarantees only one request can modify a product's stock at a time.
 */
class OrderService
{
    public function placeOrder(int $userId, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($userId, $notes) {
            $cart = Cart::with('items.product')->where('user_id', $userId)->firstOrFail();

            if ($cart->items->isEmpty()) {
                throw new \RuntimeException('Cart is empty.');
            }

            $total = 0;
            $orderItemsData = [];

            foreach ($cart->items as $cartItem) {
                /*
                 * lockForUpdate() — acquires an exclusive row-level lock.
                 * Any concurrent transaction trying to read this row for update
                 * will be blocked until this transaction finishes.
                 * This eliminates the race condition on stock_quantity.
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

                $product->decrement('stock_quantity', $cartItem->quantity);

                $subtotal = $cartItem->quantity * $product->price;
                $total += $subtotal;

                $orderItemsData[] = [
                    'product_id' => $product->id,
                    'quantity'   => $cartItem->quantity,
                    'unit_price' => $product->price,
                ];
            }

            $order = Order::create([
                'user_id'        => $userId,
                'status'         => 'processing',
                'payment_status' => 'paid',
                'total_amount'   => $total,
                'notes'          => $notes,
            ]);

            $order->items()->createMany($orderItemsData);

            $cart->items()->delete();

            return $order;
        });
    }



    
}
