<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The application cannot be activated now (not approved, already activated, or the
 * category's number sequence for the year is full). Nothing was changed.
 */
class MembershipCannotBeActivatedException extends RuntimeException {}
