<?php

namespace App\Http\Middleware;

use App\Services\LoadBalancerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Non-Functional Requirement #5: Load Distribution Middleware
 *
 * يُطبَّق هذا الـ Middleware على endpoints الثقيلة.
 * يختار الخادم (node) المناسب بناءً على الاستراتيجية المختارة،
 * ويضيف headers للاستجابة توضّح أي node استقبل الطلب.
 *
 * في بيئة الإنتاج: يكون Load Balancer خارجياً (Nginx/HAProxy/AWS ALB)
 * لكن هذا التطبيق يُوضّح المنطق الداخلي لكل استراتيجية.
 */
class LoadDistributor
{
    public function __construct(private LoadBalancerService $lb) {}

    public function handle(Request $request, Closure $next, string $strategy = 'round_robin'): Response
    {
        // اختر الـ node بناءً على الاستراتيجية
        $node = match ($strategy) {
            'weighted'          => $this->lb->weightedRoundRobin(),
            'least_connections' => $this->lb->leastConnections(),
            'random'            => $this->lb->random(),
            default             => $this->lb->roundRobin(),
        };

        // سجّل الطلب الجديد على هذا الـ node
        $this->lb->incrementLoad($node['id']);

        // أضف معلومات الـ node للـ request كي تستخدمها الـ Jobs
        $request->attributes->set('lb_node', $node);
        $request->attributes->set('lb_strategy', $strategy);

        try {
            $response = $next($request);
        } finally {
            // حرّر الـ load بعد انتهاء الطلب
            $this->lb->decrementLoad($node['id']);
        }

        // أضف headers توضيحية للاستجابة
        $response->headers->set('X-Served-By',      $node['id']);
        $response->headers->set('X-Worker-Queue',   $node['queue']);
        $response->headers->set('X-LB-Strategy',    $strategy);
        $response->headers->set('X-Node-Weight',    (string) $node['weight']);
        $response->headers->set('X-Node-Capacity',  (string) $node['capacity']);

        return $response;
    }
}
