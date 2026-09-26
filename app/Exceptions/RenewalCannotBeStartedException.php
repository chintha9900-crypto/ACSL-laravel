<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A renewal cannot be started now (one is already awaiting payment, or no
 * renewal plan/bank account is configured for this category/currency yet).
 * Nothing was changed.
 */
class RenewalCannotBeStartedException extends RuntimeException {}
