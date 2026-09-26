<?php

namespace App\Exceptions;

/**
 * A user with the applicant's email already exists. Activation never merges with,
 * reuses or modifies an existing account (docs/database/17 OD-06).
 */
class ActivationEmailAlreadyInUseException extends MembershipCannotBeActivatedException {}
