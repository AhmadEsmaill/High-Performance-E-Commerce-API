<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product Caching (NFR — Distributed Caching)
    |--------------------------------------------------------------------------
    |
    | Configuration for the distributed (Redis) cache layer that fronts the
    | products endpoints. The goal is to serve the most-requested products
    | from Redis and keep direct database queries to a minimum.
    |
    */

    // TTL (seconds) for a single cached product detail.
    'detail_ttl' => (int) env('PRODUCT_CACHE_TTL', 600),

    // TTL (seconds) for a cached product listing page.
    'listing_ttl' => (int) env('PRODUCT_LISTING_CACHE_TTL', 120),

    // How many products the "popular" endpoint returns by default.
    'popular_limit' => (int) env('PRODUCT_POPULAR_LIMIT', 10),

];
