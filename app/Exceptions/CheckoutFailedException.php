<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Actions\Checkout\PlaceOrder` when the cart is empty, or any
 * line fails its immediate pre-order revalidation (inactive/inaccessible
 * product, insufficient stock) — caught by `CheckoutController` and turned
 * into an ordinary error shown on the checkout page, never a raw exception.
 */
class CheckoutFailedException extends RuntimeException {}
