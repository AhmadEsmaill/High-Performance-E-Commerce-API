<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Non-Functional Requirement #3: Asynchronous Processing
 *
 * Email/notification sending should never block the HTTP response.
 * Dispatched after order placement; runs in background via queue worker.
 */
class SendOrderNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 30;
    public int $backoff = 60;

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        $order = Order::with('user', 'items.product')->find($this->orderId);

        if (! $order) {
            return;
        }

        // In production: Mail::to($order->user->email)->send(new OrderConfirmationMail($order));
        // Using log driver for demonstration
        Log::info('Order notification sent', [
            'order_id'   => $order->id,
            'user_email' => $order->user->email,
            'total'      => $order->total_amount,
        ]);
    }
}
