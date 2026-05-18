<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;

class CartService
{
    public function getOrCreateCart(int $userId): Cart
    {
        return Cart::firstOrCreate(['user_id' => $userId]);
    }

    public function addItem(int $userId, int $productId, int $quantity): Cart
    {
        $product = Product::active()->findOrFail($productId);

        if (! $product->isInStock($quantity)) {
            throw new \RuntimeException("Only {$product->stock_quantity} units available.");
        }

        $cart = $this->getOrCreateCart($userId);

        $existing = $cart->items()->where('product_id', $productId)->first();

        if ($existing) {
            $newQty = $existing->quantity + $quantity;

            if (! $product->isInStock($newQty)) {
                throw new \RuntimeException("Only {$product->stock_quantity} units available in total.");
            }

            $existing->update(['quantity' => $newQty]);
        } else {
            $cart->items()->create([
                'product_id' => $productId,
                'quantity'   => $quantity,
            ]);
        }

        return $cart->load('items.product');
    }

    public function updateItem(int $userId, int $cartItemId, int $quantity): Cart
    {
        $cart = Cart::where('user_id', $userId)->firstOrFail();
        $item = $cart->items()->findOrFail($cartItemId);

        if ($quantity <= 0) {
            $item->delete();
        } else {
            $product = Product::active()->findOrFail($item->product_id);

            if (! $product->isInStock($quantity)) {
                throw new \RuntimeException("Only {$product->stock_quantity} units available.");
            }

            $item->update(['quantity' => $quantity]);
        }

        return $cart->load('items.product');
    }

    public function removeItem(int $userId, int $cartItemId): Cart
    {
        $cart = Cart::where('user_id', $userId)->firstOrFail();
        $cart->items()->findOrFail($cartItemId)->delete();

        return $cart->load('items.product');
    }

    public function clearCart(int $userId): void
    {
        $cart = Cart::where('user_id', $userId)->first();
        $cart?->items()->delete();
    }
}
