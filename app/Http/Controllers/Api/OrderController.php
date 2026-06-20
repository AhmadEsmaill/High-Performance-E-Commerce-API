<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\GenerateInvoiceJob;
use App\Jobs\NotifyLowStockJob;
use App\Jobs\SendOrderNotificationJob;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(private OrderService $orderService) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::with(['items.product', 'invoice'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return $this->paginated(OrderResource::collection($orders), $orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return $this->forbidden();
        }

        $order->load('items.product', 'invoice');

        return $this->success(new OrderResource($order));
    }

    /**
     * Place an order — demonstrates all 3 non-functional requirements:
     *
     * NFR #1 (Race Condition): OrderService uses DB transaction + lockForUpdate()
     * NFR #3 (Async Queue): Invoice generation and email notification are dispatched
     *         to the queue — they run in the background, the HTTP response returns
     *         immediately without waiting for them.
     */
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orderService->placeOrder(
                $request->user()->id,
                $request->notes,
            );

            // NFR #3 — dispatch async jobs; response returns before these finish
            GenerateInvoiceJob::dispatch($order->id);
            SendOrderNotificationJob::dispatch($order->id);

            // Check each ordered product for low stock asynchronously
            $order->items->each(function ($item) {
                NotifyLowStockJob::dispatch($item->product_id);
            });

            $order->load('items.product');

            return $this->created(new OrderResource($order), 'Order placed successfully.');
        } catch (PaymentFailedException $e) {
            // Payment declined → the whole transaction rolled back (no stock taken, no order).
            return $this->error($e->getMessage(), 402);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return $this->forbidden();
        }

        if ($order->status !== 'pending') {
            return $this->error('Only pending orders can be cancelled.', 409);
        }

        $order->update(['status' => 'cancelled']);

        return $this->success(new OrderResource($order), 'Order cancelled.');
    }
}
