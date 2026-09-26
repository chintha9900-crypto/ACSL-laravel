<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The stable, lifelong member record (docs/database/04 §8). Created once, by
 * `ActivateMembership`; its number never changes. Nothing is mass assignable.
 */
class Membership extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'activated_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<MembershipApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(MembershipApplication::class, 'membership_application_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MembershipCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MembershipCategory::class, 'membership_category_id');
    }

    /**
     * @return HasMany<MembershipTerm, $this>
     */
    public function terms(): HasMany
    {
        return $this->hasMany(MembershipTerm::class)->orderBy('term_no');
    }

    /**
     * The term that is currently valid: `active` and today within its own
     * dates (docs/database/04 §8's derived-state definition). Never inferred
     * from a bare `status = active` alone — the daily expiry job may not have
     * run yet, so a term can sit at `active` past its own `expires_on`.
     *
     * "Today" is anchored to the business timezone (docs/database/04's
     * calendar-date fields are set that way by `ActivateMembership` and
     * `ConfirmPayment`), not the app's default UTC — otherwise a term that
     * just started "today" in the business timezone can appear not-yet-
     * current whenever UTC hasn't rolled over to the same calendar date yet.
     *
     * Compared as plain `Y-m-d` strings, matching `ExpireMembershipTerms` and
     * `SendMembershipRenewalReminders` — `starts_on`/`expires_on` are DATE
     * columns with no timezone of their own, so comparing them as Carbon
     * instants (each implicitly midnight in whatever timezone parsed them)
     * would reintroduce the same UTC/business-timezone offset mismatch this
     * method exists to avoid.
     */
    public function currentTerm(): ?MembershipTerm
    {
        $today = Carbon::now(config('membership.business_timezone'))->toDateString();

        return $this->terms->first(
            fn (MembershipTerm $term): bool => $term->status === MembershipTerm::STATUS_ACTIVE
                && $term->starts_on !== null
                && $term->expires_on !== null
                && $today >= $term->starts_on->toDateString()
                && $today <= $term->expires_on->toDateString()
        );
    }

    public function hasCurrentTerm(): bool
    {
        return $this->currentTerm() !== null;
    }
}
