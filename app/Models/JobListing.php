<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\JobListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An aviation job vacancy on the public `/jobs` board. Applications are made
 * on the employer's own site (`application_url`); none are collected here.
 *
 * Public visibility never relies on `status` alone: a published job stays
 * visible throughout its `application_deadline` date in Asia/Colombo and
 * disappears as soon as that date ends, whether or not `jobs:expire` has
 * run yet. That command only keeps the stored status accurate.
 */
class JobListing extends Model
{
    /** @use HasFactory<JobListingFactory> */
    use HasFactory;

    /** The timezone every application deadline is a calendar date in. */
    public const DEADLINE_TIMEZONE = 'Asia/Colombo';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PUBLISHED => 'Published',
        self::STATUS_EXPIRED => 'Expired',
    ];

    /**
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'pilots' => 'Pilots',
        'aircraft_engineering_maintenance' => 'Aircraft Engineering & Maintenance',
        'cabin_crew' => 'Cabin Crew',
        'flight_operations' => 'Flight Operations',
        'airport_operations' => 'Airport Operations',
        'ground_handling' => 'Ground Handling',
        'air_traffic_management' => 'Air Traffic Management',
        'aviation_management_administration' => 'Aviation Management & Administration',
        'aviation_training_education' => 'Aviation Training & Education',
        'other' => 'Other',
    ];

    /**
     * @var array<string, string>
     */
    public const EMPLOYMENT_TYPES = [
        'full_time' => 'Full-time',
        'part_time' => 'Part-time',
        'contract' => 'Contract',
        'temporary' => 'Temporary',
        'internship' => 'Internship',
    ];

    public const LOCATION_LOCAL = 'local';

    public const LOCATION_OVERSEAS = 'overseas';

    /**
     * @var array<string, string>
     */
    public const LOCATION_TYPES = [
        self::LOCATION_LOCAL => 'Local',
        self::LOCATION_OVERSEAS => 'Overseas',
    ];

    /**
     * `status`, `published_at` and `created_by_user_id` are deliberately not
     * fillable: they change only through explicit publish/expiry actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'company_name',
        'company_logo',
        'category',
        'employment_type',
        'location_type',
        'location',
        'country',
        'short_description',
        'description',
        'responsibilities',
        'qualifications',
        'experience',
        'additional_requirements',
        'salary',
        'date_posted',
        'application_deadline',
        'application_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_posted' => 'date',
            'application_deadline' => 'date',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Today's date in the deadline timezone, as `Y-m-d`.
     */
    public static function deadlineToday(): string
    {
        return CarbonImmutable::now(self::DEADLINE_TIMEZONE)->toDateString();
    }

    /**
     * Jobs whose stored status is Published (deadline not considered).
     *
     * @param  Builder<JobListing>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * The single public visibility rule for every listing, search and
     * detail query: published, with a deadline of today or later.
     *
     * @param  Builder<JobListing>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->published()->whereDate('application_deadline', '>=', self::deadlineToday());
    }

    /**
     * Same rule as scopePubliclyVisible(), for an already-loaded job.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && ! $this->isPastDeadline();
    }

    /**
     * Whether the application deadline date has fully ended in Asia/Colombo.
     */
    public function isPastDeadline(): bool
    {
        return $this->application_deadline->toDateString() < self::deadlineToday();
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function employmentTypeLabel(): string
    {
        return self::EMPLOYMENT_TYPES[$this->employment_type] ?? $this->employment_type;
    }

    public function locationTypeLabel(): string
    {
        return self::LOCATION_TYPES[$this->location_type] ?? $this->location_type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
