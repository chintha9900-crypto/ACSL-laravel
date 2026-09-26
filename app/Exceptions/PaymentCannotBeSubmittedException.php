<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Payment evidence cannot be submitted for this payment now (there is nothing
 * awaiting evidence, or it has already been submitted/confirmed). Nothing was
 * changed.
 */
class PaymentCannotBeSubmittedException extends RuntimeException {}
