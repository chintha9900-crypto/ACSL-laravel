<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the Jobs actions when Eloquent reports a save or delete as not
 * performed (it returned false, e.g. a model event cancelled it) — caught by
 * `Admin\JobListingController` and shown as an error instead of a success
 * message, with the transaction rolled back and no logo file lost.
 */
class JobListingNotSavedException extends RuntimeException {}
