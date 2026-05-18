<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Non-Functional Requirement #3: Asynchronous Processing
 *
 * Invoice generation is CPU/IO intensive and the user doesn't need to wait for it.
 * This job runs in the background via the queue worker, freeing the HTTP request
 * to return immediately after the order is placed.
 */
class GenerateInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        $order = Order::with('items.product', 'user')->find($this->orderId);

        if (! $order) {
            return;
        }

        if ($order->invoice()->exists()) {
            return;
        }

        Invoice::create([
            'order_id'       => $order->id,
            'invoice_number' => 'INV-' . strtoupper(Str::random(8)) . '-' . $order->id,
            'amount'         => $order->total_amount,
            'status'         => 'generated',
            'generated_at'   => now(),
        ]);
    }
}
