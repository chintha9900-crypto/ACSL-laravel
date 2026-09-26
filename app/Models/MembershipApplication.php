<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MembershipApplicationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;

/**
 * One application attempt for new membership (docs/database/04 §6).
 * Never overwritten or deleted; a reapplication is a new row.
 */
class MembershipApplication extends Model
{
    /** @use HasFactory<MembershipApplicationFactory> */
    use HasFactory, HasUlids;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_MORE_DETAILS_REQUIRED = 'more_details_required';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * The four approved statuses (docs/database/04 §6) and how admins see them.
     * `rejected` is shown as "Declined"; the stored value never changes.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_SUBMITTED => 'Submitted',
        self::STATUS_MORE_DETAILS_REQUIRED => 'More details required',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Declined',
    ];

    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_MORE_DETAILS_REQUIRED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    /**
     * Statuses of an application that is still open (undecided).
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_MORE_DETAILS_REQUIRED,
    ];

    /**
     * Mirrors the database default so a new instance is `submitted`.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => self::STATUS_SUBMITTED,
    ];

    /**
     * The attributes an applicant supplies. Status, user, review and decision
     * columns are deliberately not mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'membership_category_id',
        'full_name',
        'email',
        'mobile',
        'address',
        'aviation_role',
        'aviation_organisation',
        'study_start_date',
        'expected_completion_date',
        'years_experience',
        'previous_employers',
    ];

    /**
     * The public identifier is the ULID column; the auto-increment `id` stays internal.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * A time-limited signed link to this application's status page. The signature
     * (an HMAC of the URL under the app key) is the credential; nothing is stored.
     */
    public function statusUrl(): string
    {
        return $this->signedUrl('applications.show');
    }

    /**
     * A signed link to one of this application's applicant routes. Pass the expiry of
     * the link the applicant is already using to hand out sibling links (the response
     * form, the redirect back) that never outlive it.
     */
    public function signedUrl(string $route, ?CarbonInterface $expiresAt = null): string
    {
        return URL::temporarySignedRoute(
            $route,
            $expiresAt ?? now()->addDays(config('membership.applicant_link_days')),
            ['application' => $this],
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'study_start_date' => 'date',
            'expected_completion_date' => 'date',
            'submitted_at' => 'datetime',
            'proof_reviewed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public static function labelFor(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'membership_application_id');
    }

    /**
     * The member record created when this application was activated, if any.
     *
     * @return HasOne<Membership, $this>
     */
    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class, 'membership_application_id');
    }

    /**
     * "More details" requests for this application, oldest first.
     *
     * @return HasMany<MembershipDetailsRequest, $this>
     */
    public function detailsRequests(): HasMany
    {
        return $this->hasMany(MembershipDetailsRequest::class, 'membership_application_id')->orderBy('requested_at')->orderBy('id');
    }

    /**
     * The request the applicant still has to answer, if any.
     */
    public function openDetailsRequest(): ?MembershipDetailsRequest
    {
        return $this->detailsRequests()->whereNull('responded_at')->latest('id')->first();
    }

    /**
     * @return BelongsTo<MembershipCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MembershipCategory::class, 'membership_category_id');
    }
}
