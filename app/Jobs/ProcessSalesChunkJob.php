<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Non-Functional Requirement #4: Batch Processing
 *
 * يعالج هذا الـ Job دفعةً واحدةً (chunk) من الطلبات اليومية.
 * يُطلَق بالتوازي مع بقية chunks عبر Laravel Bus::batch().
 *
 * لماذا Chunks بدلاً من تحميل كل البيانات دفعةً واحدة؟
 * → Order::get() على 100,000 سجل = ~500MB في الذاكرة → انهيار الخادم
 * → chunk(100) = 100 سجل في الذاكرة في أي لحظة → ثابت ومحكوم
 *
 * كل Job يعالج chunk واحداً بشكل مستقل:
 * - يحسب الإيراد والعدد والكميات
 * - يخزّن النتيجة مؤقتاً في Cache
 * - الـ Job الأخير (في then callback) يجمع كل النتائج
 */
class ProcessSalesChunkJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly string $reportDate,
        public readonly int    $chunkIndex,
        public readonly array  $orderIds,  // IDs فقط، لا كائنات كاملة
    ) {
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        // لا تعالج إذا أُلغي الـ batch
        if ($this->batch()?->cancelled()) {
            return;
        }

        $orders = Order::with('items.product')
            ->whereIn('id', $this->orderIds)
            ->where('status', '!=', 'cancelled')
            ->get();

        $chunkRevenue    = 0;
        $chunkOrders     = 0;
        $chunkItems      = 0;
        $productSales    = [];
        $categorySales   = [];

        foreach ($orders as $order) {
            $chunkRevenue += $order->total_amount;
            $chunkOrders++;

            foreach ($order->items as $item) {
                $chunkItems += $item->quantity;

                $pid  = $item->product_id;
                $name = $item->product?->name ?? "Product #{$pid}";
                $cat  = $item->product?->category ?? 'unknown';

                // تجميع إحصائيات المنتج
                $productSales[$pid] = [
                    'product_id' => $pid,
                    'name'       => $name,
                    'qty_sold'   => ($productSales[$pid]['qty_sold'] ?? 0) + $item->quantity,
                    'revenue'    => ($productSales[$pid]['revenue']  ?? 0) + ($item->quantity * $item->unit_price),
                ];

                // تجميع إحصائيات الفئة
                $categorySales[$cat]['orders']  = ($categorySales[$cat]['orders']  ?? 0) + 1;
                $categorySales[$cat]['revenue'] = ($categorySales[$cat]['revenue'] ?? 0) + ($item->quantity * $item->unit_price);
            }
        }

        // خزّن نتيجة هذا الـ chunk في Cache مؤقتاً (24 ساعة)
        $cacheKey = "sales_chunk:{$this->reportDate}:{$this->chunkIndex}";
        Cache::put($cacheKey, [
            'revenue'        => $chunkRevenue,
            'orders'         => $chunkOrders,
            'items'          => $chunkItems,
            'product_sales'  => $productSales,
            'category_sales' => $categorySales,
        ], now()->addHours(24));

        // زيادة عداد الـ chunks المكتملة لهذا التقرير
        Cache::increment("sales_chunks_done:{$this->reportDate}");
    }
}
