<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function restock(int $productId, int $quantity): Product
    {
        return DB::transaction(function () use ($productId, $quantity) {
            $product = Product::lockForUpdate()->findOrFail($productId);
            $product->increment('stock_quantity', $quantity);

            return $product->fresh();
        });
    }

    public function adjustStock(int $productId, int $newQuantity): Product
    {
        return DB::transaction(function () use ($productId, $newQuantity) {
            $product = Product::lockForUpdate()->findOrFail($productId);
            $product->update(['stock_quantity' => $newQuantity]);

            return $product->fresh();
        });
    }
}
