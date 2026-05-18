<?php

namespace App\Jobs;

use App\Models\DailySalesReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Non-Functional Requirement #4: Batch Processing (التجميع النهائي)
 *
 * يُطلَق بعد اكتمال جميع ProcessSalesChunkJob بنجاح.
 * يجمع نتائج كل الـ chunks من Cache ويكتب التقرير النهائي في قاعدة البيانات.
 *
 * فصل التجميع في Job مستقل (بدلاً من closure في then()) يتجنب
 * مشكلة infinite recursion عند serialization الـ Batch.
 */
class ConsolidateSalesReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly string $date,
        public readonly int    $totalChunks,
        public readonly int    $reportId,
    ) {}

    public function handle(): void
    {
        $totals           = ['revenue' => 0, 'orders' => 0, 'items' => 0];
        $allProductSales  = [];
        $allCategorySales = [];

        for ($i = 0; $i < $this->totalChunks; $i++) {
            $chunk = Cache::get("sales_chunk:{$this->date}:{$i}");
            if (! $chunk) continue;

            $totals['revenue'] += $chunk['revenue'];
            $totals['orders']  += $chunk['orders'];
            $totals['items']   += $chunk['items'];

            foreach ($chunk['product_sales'] as $pid => $data) {
                $allProductSales[$pid]['product_id'] = $data['product_id'];
                $allProductSales[$pid]['name']       = $data['name'];
                $allProductSales[$pid]['qty_sold']   = ($allProductSales[$pid]['qty_sold']  ?? 0) + $data['qty_sold'];
                $allProductSales[$pid]['revenue']    = ($allProductSales[$pid]['revenue']   ?? 0) + $data['revenue'];
            }

            foreach ($chunk['category_sales'] as $cat => $data) {
                $allCategorySales[$cat]['orders']  = ($allCategorySales[$cat]['orders']  ?? 0) + $data['orders'];
                $allCategorySales[$cat]['revenue'] = ($allCategorySales[$cat]['revenue'] ?? 0) + $data['revenue'];
            }

            Cache::forget("sales_chunk:{$this->date}:{$i}");
        }

        uasort($allProductSales, fn ($a, $b) => $b['qty_sold'] <=> $a['qty_sold']);
        $topProducts = array_slice(array_values($allProductSales), 0, 5);

        DailySalesReport::where('id', $this->reportId)->update([
            'total_orders'       => $totals['orders'],
            'total_revenue'      => $totals['revenue'],
            'total_items_sold'   => $totals['items'],
            'top_products'       => $topProducts,
            'category_breakdown' => $allCategorySales,
            'chunks_processed'   => $this->totalChunks,
            'status'             => 'completed',
        ]);

        Cache::forget("sales_chunks_done:{$this->date}");

        Log::info("Daily sales report completed for {$this->date}", array_merge($totals, [
            'chunks' => $this->totalChunks,
        ]));
    }
}
