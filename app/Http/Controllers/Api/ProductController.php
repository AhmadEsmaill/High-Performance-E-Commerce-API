<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductCacheService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCacheService $cache)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page      = (int) $request->integer('page', 1);
        $category  = (string) $request->query('category', '');
        $search    = (string) $request->query('search', '');
        $signature = md5("c={$category}|s={$search}|p={$page}");

        // Served from Redis when warm; only the first request per filter set hits the DB.
        // Plain arrays are cached (never Eloquent models) to keep Redis payloads small
        // and avoid model-serialization pitfalls.
        $payload = $this->cache->rememberListing($signature, function () use ($category, $search) {
            $products = Product::active()
                ->when($category !== '', fn ($q) => $q->where('category', $category))
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            return [
                'data' => ProductResource::collection($products)->resolve(),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page'    => $products->lastPage(),
                    'per_page'     => $products->perPage(),
                    'total'        => $products->total(),
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data'    => $payload['data'],
            'meta'    => $payload['meta'],
        ]);
    }

    public function show(string $product): JsonResponse
    {
        $id = (int) $product;

        $data = $this->cache->rememberProduct($id, fn () => $this->resolveProduct($id));

        if (! $data) {
            return $this->notFound('Product not found.');
        }

        // Track demand so this product can surface in the "most-requested" ranking.
        $this->cache->recordHit($id);

        return $this->success($data);
    }

    /**
     * Most-requested products, served from the distributed cache.
     */
    public function popular(Request $request): JsonResponse
    {
        $limit = (int) $request->integer('limit', (int) config('products.popular_limit'));
        $ids   = $this->cache->popularIds($limit);

        $products = collect($ids)
            ->map(fn (int $id) => $this->cache->rememberProduct($id, fn () => $this->resolveProduct($id)))
            ->filter()
            ->values();

        return $this->success($products);
    }

    /**
     * Resolve a single active product to its cacheable array form, or null.
     */
    private function resolveProduct(int $id): ?array
    {
        $product = Product::active()->find($id);

        return $product ? (new ProductResource($product))->resolve() : null;
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());
        $this->cache->flush();

        return $this->created(new ProductResource($product), 'Product created successfully.');
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());
        $this->cache->flush();

        return $this->success(new ProductResource($product), 'Product updated successfully.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->update(['is_active' => false]);
        $this->cache->flush();

        return $this->success(message: 'Product deactivated successfully.');
    }
}
