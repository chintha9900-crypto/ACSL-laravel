<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The per-(category, year) counter that makes the SSSS part of a membership number
 * concurrency-safe (docs/database/04 §10). Read only under a row lock.
 */
class MembershipNumberSequence extends Model {}
