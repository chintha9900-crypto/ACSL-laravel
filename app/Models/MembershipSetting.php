<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Typed singleton of membership-workflow settings (docs/database/04 §5).
 */
class MembershipSetting extends Model
{
    public $incrementing = false;

    /**
     * The singleton row, created from the column defaults on first use.
     */
    public static function current(): static
    {
        static::query()->insertOrIgnore([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return static::query()->findOrFail(1);
    }
}
