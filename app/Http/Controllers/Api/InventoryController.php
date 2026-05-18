<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\InventoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    use ApiResponse;

    public function __construct(private InventoryService $inventoryService) {}

    public function restock(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = $this->inventoryService->restock($product->id, $validated['quantity']);

        return $this->success(new ProductResource($product), "Restocked +{$validated['quantity']} units.");
    }

    public function adjust(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        $product = $this->inventoryService->adjustStock($product->id, $validated['quantity']);

        return $this->success(new ProductResource($product), 'Stock adjusted.');
    }

    public function lowStock(): JsonResponse
    {
        $products = Product::where('stock_quantity', '<=', 10)
            ->where('is_active', true)
            ->orderBy('stock_quantity')
            ->get();

        return $this->success(ProductResource::collection($products), 'Low stock products.');
    }
}
