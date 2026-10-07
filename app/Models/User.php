<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Authorization\Ability;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Memoised per request: the names of every permission `role` currently
     * grants, loaded at most once per instance.
     */
    private ?Collection $grantedPermissionNames = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * `role` stores the role name directly (not a foreign-key id).
     *
     * @return BelongsTo<Role, $this>
     */
    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'name');
    }

    /**
     * Whether this user's role — and only their role; this performs no
     * ownership check — grants the given permission. An admin always holds
     * every permission in practice via the `Gate::before` bypass in
     * `AppServiceProvider`; this method is what every other role's Policy
     * checks go through. A suspended/pending account never holds any
     * permission, regardless of role.
     */
    public function hasPermission(Ability $ability): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return $this->grantedPermissionNames()->contains($ability->value);
    }

    /**
     * @return Collection<int, string>
     */
    private function grantedPermissionNames(): Collection
    {
        return $this->grantedPermissionNames ??= DB::table('roles')
            ->join('permission_role', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('roles.name', $this->role)
            ->pluck('permissions.name');
    }
}
