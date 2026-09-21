<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountSetupToken extends Model
{
    public const PURPOSE_ACCOUNT_SETUP = 'account_setup';

    /**
     * The attributes that are mass assignable.
     *
     * `live_key` is a generated column and is never written.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'membership_id',
        'purpose',
        'token_hash',
        'expires_at',
        'issued_by_user_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    /**
     * The hash under which a plaintext token is stored and looked up.
     */
    public static function hashFor(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Tokens that are unused, not invalidated and not yet expired.
     *
     * @param  Builder<AccountSetupToken>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
