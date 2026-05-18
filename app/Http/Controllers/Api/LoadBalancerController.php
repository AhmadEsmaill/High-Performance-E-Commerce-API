<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateInvoiceJob;
use App\Services\LoadBalancerService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Non-Functional Requirement #5: Load Distribution
 *
 * endpoints تُوضّح توزيع الأحمال وتتيح مراقبة إحصائيات الخوادم.
 */
class LoadBalancerController extends Controller
{
    use ApiResponse;

    public function __construct(private LoadBalancerService $lb) {}

    /**
     * عرض إحصائيات توزيع الأحمال الحالية.
     */
    public function stats(): JsonResponse
    {
        $stats = $this->lb->getStats();

        return $this->success([
            'nodes'            => $stats['nodes'],
            'total_requests'   => $stats['total_requests'],
            'strategy_summary' => $stats['strategy_summary'],
            'explanation'      => [
                'round_robin'       => 'كل طلب يذهب للخادم التالي في الدورة — عادل وبسيط',
                'weighted'          => 'الخوادم الأقوى (weight أعلى) تستقبل نسبة أكبر من الطلبات',
                'least_connections' => 'الطلب يذهب للخادم الأقل انشغالاً — أفضل للطلبات غير المتساوية',
                'random'            => 'اختيار عشوائي — بسيط لكن غير مضمون التوزيع العادل',
            ],
        ]);
    }

    /**
     * محاكاة إرسال N طلب وتوزيعها على الخوادم.
     * يُظهر كيف تُوزَّع الأحمال بكل استراتيجية.
     */
    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'requests'  => ['required', 'integer', 'min:1', 'max:100'],
            'strategy'  => ['required', 'in:round_robin,weighted,least_connections,random'],
        ]);

        $count    = $validated['requests'];
        $strategy = $validated['strategy'];
        $results  = [];

        for ($i = 1; $i <= $count; $i++) {
            $node = match ($strategy) {
                'weighted'          => $this->lb->weightedRoundRobin(),
                'least_connections' => $this->lb->leastConnections(),
                'random'            => $this->lb->random(),
                default             => $this->lb->roundRobin(),
            };

            $results[] = [
                'request_number' => $i,
                'assigned_to'    => $node['id'],
                'queue'          => $node['queue'],
            ];
        }

        // تلخيص التوزيع
        $distribution = array_count_values(array_column($results, 'assigned_to'));
        arsort($distribution);

        $summary = array_map(function ($nodeId, $count) use ($results) {
            $percentage = round(($count / count($results)) * 100, 1);
            return [
                'node'       => $nodeId,
                'requests'   => $count,
                'percentage' => "{$percentage}%",
            ];
        }, array_keys($distribution), array_values($distribution));

        return $this->success([
            'strategy'     => $strategy,
            'total_sent'   => $count,
            'distribution' => array_values($summary),
            'detail'       => $results,
        ], "Simulated {$count} requests using [{$strategy}] strategy.");
    }

    /**
     * إعادة تعيين إحصائيات الخوادم.
     */
    public function reset(): JsonResponse
    {
        $this->lb->resetStats();

        return $this->success(message: 'Load balancer stats reset.');
    }

    /**
     * عرض تكوين الخوادم المتاحة.
     */
    public function nodes(): JsonResponse
    {
        $nodes = LoadBalancerService::getNodes();

        $totalWeight = array_sum(array_column($nodes, 'weight'));

        $enriched = array_map(function ($node) use ($totalWeight) {
            return array_merge($node, [
                'traffic_share' => round(($node['weight'] / $totalWeight) * 100, 1) . '%',
                'active_load'   => (int) \Illuminate\Support\Facades\Cache::get('lb:node_load:' . $node['id'], 0),
            ]);
        }, $nodes);

        return $this->success($enriched, 'Available load balancer nodes.');
    }
}
