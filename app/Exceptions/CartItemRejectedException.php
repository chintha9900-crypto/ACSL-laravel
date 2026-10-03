<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Actions\Cart\{AddCartItem,UpdateCartItemQuantity}` when a
 * product is inactive, inaccessible to the acting user, or the requested
 * quantity exceeds available stock — caught by `CartController` and turned
 * into an ordinary validation error, never a raw exception.
 */
class CartItemRejectedException extends RuntimeException {}
