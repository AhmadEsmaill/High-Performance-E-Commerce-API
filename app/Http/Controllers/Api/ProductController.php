<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $products = Product::active()
            ->when($request->category, fn ($q) => $q->where('category', $request->category))
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return $this->paginated(ProductResource::collection($products), $products);
    }

    public function show(Product $product): JsonResponse
    {
        if (! $product->is_active) {
            return $this->notFound('Product not found.');
        }

        return $this->success(new ProductResource($product));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return $this->created(new ProductResource($product), 'Product created successfully.');
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());

        return $this->success(new ProductResource($product), 'Product updated successfully.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->update(['is_active' => false]);

        return $this->success(message: 'Product deactivated successfully.');
    }
}
