<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Actions\Orders\UpdateOrderStatus` when the requested
 * status is not one of `Order::TRANSITIONS[$order->status]` — covers both
 * a genuinely disallowed jump (e.g. `packed` → `delivered`) and a repeated
 * attempt to act on an already-terminal order (`cancelled`/`refunded` have
 * no outgoing edges at all).
 */
class InvalidOrderStatusTransitionException extends RuntimeException {}
