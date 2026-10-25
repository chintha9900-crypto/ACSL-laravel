<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A School Club student batch decision could not be applied; nothing changed.
 */
class StudentBatchCannotBeReviewedException extends RuntimeException {}
