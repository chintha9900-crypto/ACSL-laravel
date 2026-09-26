<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * There is no open "more details" request to answer (never requested, or already
 * answered — for example by a repeated submission).
 */
class MoreDetailsNotRequestedException extends RuntimeException {}
