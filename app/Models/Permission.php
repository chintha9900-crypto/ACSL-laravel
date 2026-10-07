<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One granular capability a role may hold (RBAC foundation). The `name`
 * column matches a `App\Support\Authorization\Ability` enum value exactly —
 * that enum is what application code checks against; this model exists so
 * the grant itself is a normal, queryable, testable Eloquent relationship
 * rather than only a raw lookup table.
 *
 * Seeded by `2026_10_07_000005_seed_roles_and_permissions.php`.
 */
class Permission extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'label',
    ];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}
