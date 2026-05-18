<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailySalesReportResource;
use App\Jobs\GenerateDailySalesReportJob;
use App\Models\DailySalesReport;
use App\Models\Order;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Non-Functional Requirement #4: Batch Processing
 *
 * endpoints تتيح طلب تقارير المبيعات اليومية ومتابعة حالتها.
 */
class ReportController extends Controller
{
    use ApiResponse;

    /**
     * طلب تقرير مبيعات يوم معين — يُطلق Batch Job في الخلفية.
     */
    public function requestDailySalesReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $date = $validated['date'] ?? now()->subDay()->toDateString();

        $existing = DailySalesReport::where('report_date', $date)
            ->where('status', 'completed')
            ->first();

        if ($existing) {
            return $this->success(
                new DailySalesReportResource($existing),
                "Report for {$date} already exists."
            );
        }

        // حساب إجمالي الطلبات لإظهار عدد الـ chunks المتوقع
        $totalOrders = Order::whereDate('created_at', $date)
            ->where('status', '!=', 'cancelled')
            ->count();

        $chunkSize   = GenerateDailySalesReportJob::CHUNK_SIZE;
        $totalChunks = (int) ceil($totalOrders / $chunkSize);

        // إطلاق الـ Job الخلفي — الـ response يعود فوراً
        GenerateDailySalesReportJob::dispatch($date)->onQueue('reports');

        return $this->success([
            'date'                 => $date,
            'total_orders_to_process' => $totalOrders,
            'chunk_size'           => $chunkSize,
            'expected_chunks'      => $totalChunks,
            'status'               => 'processing',
            'message'              => 'استخدم GET /api/reports/daily/{date} لمتابعة الحالة',
        ], "Batch processing started for {$date}. Processing {$totalOrders} orders in {$totalChunks} parallel chunks.");
    }

    /**
     * عرض حالة ونتيجة تقرير يوم معين.
     */
    public function showDailySalesReport(string $date): JsonResponse
    {
        $report = DailySalesReport::where('report_date', $date)->first();

        if (! $report) {
            return $this->notFound("No report found for {$date}. Trigger one via POST /api/reports/daily.");
        }

        return $this->success(new DailySalesReportResource($report));
    }

    /**
     * قائمة بجميع التقارير المُنجزة.
     */
    public function listReports(): JsonResponse
    {
        $reports = DailySalesReport::orderBy('report_date', 'desc')->paginate(10);

        return $this->paginated(DailySalesReportResource::collection($reports), $reports);
    }
}
