<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| ProductCacheService — Distributed Caching (NFR)
|--------------------------------------------------------------------------
|
| Fronts the read-heavy products endpoints with a distributed Redis cache so
| that the most-requested products are served straight from memory instead of
| hitting the database on every request.
|
|  - Product details and listing pages are cached under the "products" tag,
|    which lets a single write invalidate everything that could be stale.
|  - A Redis sorted set tracks how often each product is requested, powering
|    the "most-requested products" (popular) listing.
|
*/
class ProductCacheService
{
    /** Cache tag grouping every product-related entry for one-shot invalidation. */
    private const TAG = 'products';

    /** Redis sorted set holding the request count per product id. */
    private const POPULARITY_KEY = 'products:popularity';

    /**
     * Remember a single product detail, keyed by id.
     */
    public function rememberProduct(int $id, Closure $callback): mixed
    {
        return Cache::tags(self::TAG)->remember(
            "product:{$id}",
            (int) config('products.detail_ttl'),
            $callback
        );
    }

    /**
     * Remember a product listing page, keyed by a signature of its filters.
     */
    public function rememberListing(string $signature, Closure $callback): mixed
    {
        return Cache::tags(self::TAG)->remember(
            "products:list:{$signature}",
            (int) config('products.listing_ttl'),
            $callback
        );
    }

    /**
     * Record a hit so the product climbs the "most-requested" ranking.
     */
    public function recordHit(int $id): void
    {
        Redis::zincrby(self::POPULARITY_KEY, 1, $id);
    }

    /**
     * Ids of the most-requested products, ordered by popularity (desc).
     *
     * @return array<int, int>
     */
    public function popularIds(int $limit): array
    {
        $ids = Redis::zrevrange(self::POPULARITY_KEY, 0, max(0, $limit - 1));

        return array_map('intval', $ids);
    }

    /**
     * Invalidate every cached product entry. Called after any write so reads
     * never serve stale prices or stock.
     */
    public function flush(): void
    {
        Cache::tags(self::TAG)->flush();
    }
}
