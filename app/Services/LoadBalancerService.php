<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Non-Functional Requirement #5: Load Distribution
 *
 * يُحاكي هذا الـ Service توزيع الأحمال على عدة "خوادم" (workers).
 * في بيئة الإنتاج الحقيقية، كل worker يعمل على خادم مستقل.
 * هنا نُمثّل الخوادم بـ Queue channels مختلفة، كل منها يُعالَج
 * بواسطة worker مستقل.
 *
 * الاستراتيجيات المدعومة:
 *   1. Round-Robin       → الطلبات تُوزَّع بالتناوب على الخوادم
 *   2. Weighted          → الخوادم الأقوى تستقبل نسبة أعلى من الطلبات
 *   3. Least-Connections → الطلب يذهب للخادم الأقل تحميلاً
 *   4. Random            → توزيع عشوائي (أبسط تطبيق)
 */
class LoadBalancerService
{
    // الخوادم المحاكاة — في الإنتاج هذه queue workers على servers مختلفة
    private const NODES = [
        ['id' => 'node-1', 'queue' => 'worker-1', 'weight' => 3, 'capacity' => 100],
        ['id' => 'node-2', 'queue' => 'worker-2', 'weight' => 2, 'capacity' => 80],
        ['id' => 'node-3', 'queue' => 'worker-3', 'weight' => 1, 'capacity' => 50],
    ];

    private const COUNTER_KEY    = 'lb:round_robin_counter';
    private const LOAD_KEY       = 'lb:node_load:';
    private const STATS_KEY      = 'lb:stats';
    private const COUNTER_TTL    = 86400; // 24 ساعة

    // ─── Round-Robin ───────────────────────────────────────────────────────

    /**
     * Round-Robin: كل طلب يذهب للخادم التالي في الدورة.
     * بسيط وعادل لكن لا يأخذ بعين الاعتبار الحمل الفعلي.
     *
     * مثال بـ 3 خوادم: 1→2→3→1→2→3→...
     */
    public function roundRobin(): array
    {
        $counter = (int) Cache::get(self::COUNTER_KEY, 0);
        $nodeIndex = $counter % count(self::NODES);
        Cache::put(self::COUNTER_KEY, $counter + 1, self::COUNTER_TTL);

        $node = self::NODES[$nodeIndex];
        $this->recordRequest($node['id'], 'round_robin');

        return $node;
    }

    // ─── Weighted Round-Robin ──────────────────────────────────────────────

    /**
     * Weighted Round-Robin: الخوادم الأقوى تستقبل نسبة أكبر.
     * weights: node-1=3, node-2=2, node-3=1 → من كل 6 طلبات:
     *   node-1 يستقبل 3 طلبات (50%)
     *   node-2 يستقبل 2 طلبات (33%)
     *   node-3 يستقبل 1 طلب  (17%)
     */
    public function weightedRoundRobin(): array
    {
        // بناء قائمة موسّعة بناءً على الأوزان
        $expandedNodes = [];
        foreach (self::NODES as $node) {
            for ($i = 0; $i < $node['weight']; $i++) {
                $expandedNodes[] = $node;
            }
        }

        $counter   = (int) Cache::get(self::COUNTER_KEY . ':weighted', 0);
        $nodeIndex = $counter % count($expandedNodes);
        Cache::put(self::COUNTER_KEY . ':weighted', $counter + 1, self::COUNTER_TTL);

        $node = $expandedNodes[$nodeIndex];
        $this->recordRequest($node['id'], 'weighted');

        return $node;
    }

    // ─── Least Connections ────────────────────────────────────────────────

    /**
     * Least-Connections: يوجّه الطلب للخادم الأقل انشغالاً حالياً.
     * أفضل استراتيجية للطلبات ذات الأوزان المتفاوتة (طلبات قصيرة وطويلة).
     */
    public function leastConnections(): array
    {
        $leastLoaded = null;
        $minLoad     = PHP_INT_MAX;

        foreach (self::NODES as $node) {
            $load = (int) Cache::get(self::LOAD_KEY . $node['id'], 0);

            // نحسب نسبة التحميل كي لا نُرهق الخوادم الأضعف capacity
            $loadRatio = $node['capacity'] > 0 ? $load / $node['capacity'] : PHP_INT_MAX;

            if ($loadRatio < $minLoad) {
                $minLoad     = $loadRatio;
                $leastLoaded = $node;
            }
        }

        $this->recordRequest($leastLoaded['id'], 'least_connections');
        return $leastLoaded;
    }

    // ─── Random ──────────────────────────────────────────────────────────

    public function random(): array
    {
        $node = self::NODES[array_rand(self::NODES)];
        $this->recordRequest($node['id'], 'random');
        return $node;
    }

    // ─── إدارة الأحمال ───────────────────────────────────────────────────

    public function incrementLoad(string $nodeId): void
    {
        Cache::increment(self::LOAD_KEY . $nodeId);
    }

    public function decrementLoad(string $nodeId): void
    {
        $current = (int) Cache::get(self::LOAD_KEY . $nodeId, 0);
        Cache::put(self::LOAD_KEY . $nodeId, max(0, $current - 1), self::COUNTER_TTL);
    }

    // ─── إحصائيات ────────────────────────────────────────────────────────

    public function getStats(): array
    {
        $stats = Cache::get(self::STATS_KEY, []);

        $nodes = array_map(function ($node) use ($stats) {
            return [
                'id'              => $node['id'],
                'queue'           => $node['queue'],
                'weight'          => $node['weight'],
                'capacity'        => $node['capacity'],
                'active_load'     => (int) Cache::get(self::LOAD_KEY . $node['id'], 0),
                'requests_served' => $stats[$node['id']]['total']   ?? 0,
                'by_strategy'     => $stats[$node['id']]['strategies'] ?? [],
            ];
        }, self::NODES);

        $total = array_sum(array_column($nodes, 'requests_served'));

        return [
            'nodes'            => $nodes,
            'total_requests'   => $total,
            'strategy_summary' => $this->strategyBreakdown($stats),
        ];
    }

    public function resetStats(): void
    {
        Cache::forget(self::STATS_KEY);
        Cache::forget(self::COUNTER_KEY);
        Cache::forget(self::COUNTER_KEY . ':weighted');
        foreach (self::NODES as $node) {
            Cache::forget(self::LOAD_KEY . $node['id']);
        }
    }

    public static function getNodes(): array
    {
        return self::NODES;
    }

    // ─── خاص ─────────────────────────────────────────────────────────────

    private function recordRequest(string $nodeId, string $strategy): void
    {
        $stats = Cache::get(self::STATS_KEY, []);

        $stats[$nodeId]['total'] = ($stats[$nodeId]['total'] ?? 0) + 1;
        $stats[$nodeId]['strategies'][$strategy] = ($stats[$nodeId]['strategies'][$strategy] ?? 0) + 1;

        Cache::put(self::STATS_KEY, $stats, self::COUNTER_TTL);
    }

    private function strategyBreakdown(array $stats): array
    {
        $breakdown = [];
        foreach ($stats as $nodeStats) {
            foreach ($nodeStats['strategies'] ?? [] as $strategy => $count) {
                $breakdown[$strategy] = ($breakdown[$strategy] ?? 0) + $count;
            }
        }
        return $breakdown;
    }
}
