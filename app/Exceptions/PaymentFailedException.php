<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the payment step of an order is declined. Because the charge runs
 * inside the order's DB transaction, throwing this rolls back the stock
 * decrement and the order/items creation — nothing is persisted. Maps to
 * HTTP 402 Payment Required.
 */
class PaymentFailedException extends RuntimeException
{
}
