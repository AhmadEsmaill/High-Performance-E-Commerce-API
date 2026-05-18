<?php

namespace App\Jobs;

use App\Models\DailySalesReport;
use App\Models\Order;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Non-Functional Requirement #4: Batch Processing (المنسّق الرئيسي)
 *
 * هذا الـ Job هو المنسّق (Orchestrator):
 * 1. يجلب IDs الطلبات اليومية (بدون تحميل البيانات الكاملة)
 * 2. يقسّمها إلى chunks بحجم CHUNK_SIZE
 * 3. يُطلق كل chunk كـ Job مستقل عبر Bus::batch()
 * 4. عند اكتمال جميع الـ chunks → يُطلق ConsolidateSalesReportJob
 *
 * Bus::batch() يُوزّع الـ Jobs على Workers المتاحين بشكل تلقائي،
 * ما يعني أن معالجة 10,000 طلب تُوزَّع على مثلاً 4 workers
 * كل منه يعالج 2500 سجل، بدلاً من معالجتها بشكل تسلسلي.
 *
 * ملاحظة معمارية: الـ then() callback لا يلتقط $this تجنباً لـ
 * infinite recursion عند serialization الـ batch في قاعدة البيانات.
 * بدلاً من ذلك نستخدم static closure تمرّر البيانات كـ primitives فقط.
 */
class GenerateDailySalesReportJob implements ShouldQueue
{
    use Queueable;

    public const CHUNK_SIZE = 100;

    public int $tries   = 1;
    public int $timeout = 300;

    public function __construct(public readonly string $reportDate) {}

    public function handle(): void
    {
        $date = $this->reportDate;

        // إنشاء سجل التقرير بحالة "processing"
        $report = DailySalesReport::updateOrCreate(
            ['report_date' => $date],
            ['status' => 'processing', 'chunks_processed' => 0]
        );

        // جلب IDs فقط — لا نحمّل البيانات الكاملة هنا لتوفير الذاكرة
        $orderIds = Order::whereDate('created_at', $date)
            ->where('status', '!=', 'cancelled')
            ->pluck('id')
            ->toArray();

        if (empty($orderIds)) {
            $report->update(['status' => 'completed', 'total_orders' => 0]);
            return;
        }

        // تقسيم الـ IDs إلى chunks
        $chunks      = array_chunk($orderIds, self::CHUNK_SIZE);
        $totalChunks = count($chunks);
        $reportId    = $report->id;

        // بناء مصفوفة الـ Jobs (chunk واحد = Job واحد)
        $jobs = [];
        foreach ($chunks as $index => $chunkIds) {
            $jobs[] = new ProcessSalesChunkJob($date, $index, $chunkIds);
        }

        /*
         * Bus::batch() — يُطلق كل الـ Jobs بشكل متوازٍ على الـ workers المتاحة.
         *
         * IMPORTANT: الـ closures هنا static ولا تلتقط $this
         * لأن Laravel يحاول serialize الـ batch+callbacks معاً في قاعدة البيانات،
         * فلو التقطت $this (الـ Job الحالي) سيحدث infinite recursion.
         * الحل: تمرير القيم كـ primitives (string, int) فقط داخل use().
         */
        $batch = Bus::batch($jobs)
            ->then(static function (Batch $batch) use ($date, $totalChunks, $reportId) {
                // عند اكتمال جميع الـ chunks → أطلق job التجميع
                ConsolidateSalesReportJob::dispatch($date, $totalChunks, $reportId)
                    ->onQueue('reports');
            })
            ->catch(static function (Batch $batch, \Throwable $e) use ($date, $reportId) {
                DailySalesReport::where('id', $reportId)->update(['status' => 'failed']);
                Log::error("Daily sales report failed for {$date}", ['error' => $e->getMessage()]);
            })
            ->name("Daily Sales Report: {$date}")
            ->onQueue('reports')
            ->dispatch();

        $report->update(['batch_id' => $batch->id]);

        Log::info("Batch processing started for {$date}", [
            'total_orders' => count($orderIds),
            'total_chunks' => $totalChunks,
            'chunk_size'   => self::CHUNK_SIZE,
            'batch_id'     => $batch->id,
        ]);
    }
}
