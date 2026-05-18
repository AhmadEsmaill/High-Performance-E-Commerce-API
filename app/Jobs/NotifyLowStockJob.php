<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Non-Functional Requirement #3: Asynchronous Processing
 *
 * Low-stock alerts are triggered after stock decrements but run asynchronously
 * so inventory checks don't add latency to the order placement flow.
 */
class NotifyLowStockJob implements ShouldQueue
{
    use Queueable;

    public const LOW_STOCK_THRESHOLD = 5;

    public function __construct(public readonly int $productId) {}

    public function handle(): void
    {
        $product = Product::find($this->productId);

        if (! $product || $product->stock_quantity > self::LOW_STOCK_THRESHOLD) {
            return;
        }

        Log::warning('Low stock alert', [
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'remaining'    => $product->stock_quantity,
        ]);
    }
}
