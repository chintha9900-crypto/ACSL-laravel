<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the five approved roles (docs/architecture RBAC foundation). Exactly
 * four are stored here — `admin`, `editor`, `member`, `dev`; the fifth,
 * Guest, is simply the absence of an authenticated user and has no row.
 *
 * Seeded by `2026_10_07_000005_seed_roles_and_permissions.php`. Nothing in
 * this application creates, renames or deletes a role at runtime — role
 * management has no UI yet (explicitly out of scope for this phase).
 */
class Role extends Model
{
    public const ADMIN = 'admin';

    public const EDITOR = 'editor';

    public const MEMBER = 'member';

    public const DEV = 'dev';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'label',
    ];

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    /**
     * `users.role` stores the role name directly (not a foreign-key id), so
     * this joins on that string column rather than a `role_id`.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'name');
    }
}
