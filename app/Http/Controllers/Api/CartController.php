<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Services\CartService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    use ApiResponse;

    public function __construct(private CartService $cartService) {}

    public function index(Request $request): JsonResponse
    {
        $cart = $this->cartService->getOrCreateCart($request->user()->id);
        $cart->load('items.product');

        return $this->success(new CartResource($cart));
    }

    public function add(AddToCartRequest $request): JsonResponse
    {
        try {
            $cart = $this->cartService->addItem(
                $request->user()->id,
                $request->product_id,
                $request->quantity,
            );

            return $this->success(new CartResource($cart), 'Item added to cart.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function update(UpdateCartItemRequest $request, int $cartItemId): JsonResponse
    {
        try {
            $cart = $this->cartService->updateItem(
                $request->user()->id,
                $cartItemId,
                $request->quantity,
            );

            return $this->success(new CartResource($cart), 'Cart updated.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function remove(Request $request, int $cartItemId): JsonResponse
    {
        try {
            $cart = $this->cartService->removeItem($request->user()->id, $cartItemId);

            return $this->success(new CartResource($cart), 'Item removed from cart.');
        } catch (\RuntimeException $e) {
            return $this->notFound('Cart item not found.');
        }
    }

    public function clear(Request $request): JsonResponse
    {
        $this->cartService->clearCart($request->user()->id);

        return $this->success(message: 'Cart cleared.');
    }
}
