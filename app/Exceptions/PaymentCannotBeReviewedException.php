<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A payment cannot be confirmed or rejected now (it is not awaiting review —
 * already decided, or no evidence has been submitted yet). Nothing was changed.
 */
class PaymentCannotBeReviewedException extends RuntimeException {}
