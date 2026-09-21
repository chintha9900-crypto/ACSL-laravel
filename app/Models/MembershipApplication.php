<?php

namespace App\Models;

use Database\Factories\MembershipApplicationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * @return BelongsTo<MembershipCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MembershipCategory::class, 'membership_category_id');
    }
}
