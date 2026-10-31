<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A batch of students a School Club representative submits for admin review.
 * Student accounts and memberships exist only once a batch is approved.
 * Written only by server-side actions (`forceCreate`/`forceFill`).
 */
class SchoolClubStudentBatch extends Model
{
    use HasUlids;

    /**
     * The Excel list has been uploaded; the principal-signed PDF has not. Not
     * yet visible to admin review.
     */
    public const STATUS_AWAITING_SIGNED_LIST = 'awaiting_signed_list';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_MORE_DETAILS_REQUIRED = 'more_details_required';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Statuses in which the batch still awaits a decision.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_MORE_DETAILS_REQUIRED];

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_AWAITING_SIGNED_LIST => 'Awaiting principal-signed list',
        self::STATUS_SUBMITTED => 'Awaiting review',
        self::STATUS_MORE_DETAILS_REQUIRED => 'More details required',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Declined',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
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
     * The parent (main) School Club membership the students join.
     *
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return HasMany<SchoolClubStudentEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(SchoolClubStudentEntry::class)->orderBy('id');
    }

    /**
     * The principal-signed student list (latest upload).
     *
     * @return HasOne<Document, $this>
     */
    public function studentList(): HasOne
    {
        return $this->hasOne(Document::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('kind', Document::KIND_SCHOOL_CLUB_STUDENT_LIST));
    }

    /**
     * The completed Excel template the entries were read from (latest upload).
     *
     * @return HasOne<Document, $this>
     */
    public function spreadsheet(): HasOne
    {
        return $this->hasOne(Document::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('kind', Document::KIND_SCHOOL_CLUB_STUDENT_SPREADSHEET));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
