<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The application is no longer awaiting a decision (already decided, awaiting the
 * applicant, or decided by another admin a moment ago).
 */
class MembershipApplicationCannotBeReviewedException extends RuntimeException {}
