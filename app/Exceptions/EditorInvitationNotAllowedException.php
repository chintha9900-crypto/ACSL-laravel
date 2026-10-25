<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The email already belongs to an account that an editor invitation must not
 * touch (any account other than an editor still pending setup).
 */
class EditorInvitationNotAllowedException extends RuntimeException {}
