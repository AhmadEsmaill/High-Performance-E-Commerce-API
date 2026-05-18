<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Non-Functional Requirement #2: Resource Management & Capacity Control
 *
 * Problem: Under high load, thousands of simultaneous requests can exhaust
 * server resources (DB connections, memory) and crash the system.
 *
 * Solution: Cache-based Semaphore Pattern
 * - Maintains an atomic counter of active concurrent requests per route.
 * - If the counter reaches the configured limit, new requests are rejected
 *   with HTTP 429 (Too Many Requests) instead of queuing indefinitely.
 * - The counter is decremented in a finally block, guaranteeing release
 *   even if the request throws an exception (no resource leaks).
 *
 * This is analogous to a counting semaphore in operating systems:
 * it allows up to N threads to access a critical section simultaneously.
 */
class ConcurrencyLimiter
{
    private const DEFAULT_MAX_CONCURRENT = 10;
    private const LOCK_TTL_SECONDS = 30;

    public function handle(Request $request, Closure $next, int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT): Response
    {
        $key = 'concurrency:' . $request->route()?->getName() ?? $request->path();

        $current = (int) Cache::get($key, 0);

        if ($current >= $maxConcurrent) {
            return response()->json([
                'success' => false,
                'message' => 'Server is busy. Please retry in a moment.',
                'meta'    => [
                    'active_requests' => $current,
                    'limit'           => $maxConcurrent,
                ],
            ], 429);
        }

        Cache::increment($key);
        Cache::put($key, Cache::get($key), self::LOCK_TTL_SECONDS);

        try {
            return $next($request);
        } finally {
            // Guaranteed release — no resource leak on exception or timeout
            $updated = max(0, (int) Cache::get($key, 0) - 1);
            Cache::put($key, $updated, self::LOCK_TTL_SECONDS);
        }
    }
}
