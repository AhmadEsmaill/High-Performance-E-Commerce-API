<?php

namespace App\Services;

use App\Exceptions\PaymentFailedException;
use Illuminate\Support\Str;

/**
 * Simulated payment gateway.
 *
 * The charge is invoked from inside OrderService's DB transaction so that
 * payment, stock update and order creation form a single atomic unit: if the
 * charge is declined (PaymentFailedException) the surrounding transaction rolls
 * back and no stock is consumed and no order is recorded.
 *
 * In a real system this would call an external PSP; here it authorises
 * synchronously and returns a gateway reference. Swap/bind a different
 * implementation in tests to simulate declines.
 */
class PaymentService
{
    /**
     * Authorise and capture a charge for the given amount.
     *
     * @return string gateway transaction reference
     *
     * @throws PaymentFailedException when the charge is declined
     */
    public function charge(int $userId, float $amount): string
    {
        if ($amount <= 0) {
            throw new PaymentFailedException('Charge amount must be greater than zero.');
        }

        return 'PAY-' . strtoupper(Str::random(10));
    }
}
