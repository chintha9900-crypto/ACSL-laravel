<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Actions\Catalogue\AdjustInventory::remove()` when removing
 * the requested amount would take stock below zero — caught by
 * `Admin\InventoryController` and turned into an ordinary validation error,
 * never a raw database exception.
 */
class InsufficientStockException extends RuntimeException {}
