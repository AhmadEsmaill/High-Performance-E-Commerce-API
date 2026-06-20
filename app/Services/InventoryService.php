<?php

namespace App\Services;

use App\Exceptions\StaleStockException;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Concurrency Control on sensitive stock updates (NFR).
 *
 * Two complementary strategies are used across the codebase:
 *
 *  - Pessimistic Locking (SELECT ... FOR UPDATE) — used on the high-contention
 *    checkout path (OrderService::placeOrder) and on restock, where we want to
 *    serialise writers and read-modify-write the current quantity safely.
 *
 *  - Optimistic Locking (version check) — used on adjustStock, the admin
 *    "set absolute quantity" path. The admin reads the product (and its
 *    lock_version), then submits the new quantity together with the version
 *    they saw. The update only succeeds if nobody else changed the row in the
 *    meantime; otherwise a StaleStockException (HTTP 409) is raised. This avoids
 *    holding a DB lock while an admin edit form sits open.
 */
class InventoryService
{
    public function __construct(private readonly ProductCacheService $cache)
    {
    }

    /**
     * Increase stock. Pessimistic lock — read-modify-write under a row lock.
     */
    public function restock(int $productId, int $quantity): Product
    {
        $product = DB::transaction(function () use ($productId, $quantity) {
            $product = Product::lockForUpdate()->findOrFail($productId);
            $product->increment('stock_quantity', $quantity);
            $product->increment('lock_version');

            return $product->fresh();
        });

        $this->cache->flush();

        return $product;
    }

    /**
     * Set stock to an absolute value. Optimistic lock — the write is guarded by
     * the lock_version the caller last saw.
     *
     * @throws StaleStockException when the product was modified concurrently.
     */
    public function adjustStock(int $productId, int $newQuantity, int $expectedVersion): Product
    {
        // Conditional update: only applies when the version still matches.
        // The version is bumped atomically so any concurrent writer is detected.
        $affected = Product::query()
            ->whereKey($productId)
            ->where('lock_version', $expectedVersion)
            ->update([
                'stock_quantity' => $newQuantity,
                'lock_version'   => DB::raw('lock_version + 1'),
            ]);

        if ($affected === 0) {
            // Version no longer matches → someone else updated this product first.
            $current = (int) (Product::whereKey($productId)->value('lock_version') ?? $expectedVersion);

            throw new StaleStockException($productId, $current);
        }

        $this->cache->flush();

        return Product::findOrFail($productId);
    }
}
