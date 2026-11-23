<?php

namespace App\Models;

use Database\Factories\EventListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/database/10_CONTENT_SCHEMA.md §7. `content` is sanitised HTML from the
 * rich-text editor (App\Support\HtmlSanitizer); older events hold plain text,
 * which `HtmlSanitizer::forDisplay()` keeps readable.
 *
 * Upcoming vs past (approved rule): an event stays upcoming throughout its
 * scheduled day in Asia/Colombo and becomes past after midnight. `starts_at`
 * holds the local Sri Lankan date/time exactly as entered (the app runs in
 * UTC and no conversion is applied), so it is compared with the start of
 * today in Colombo as a plain wall-clock value — never with the UTC clock.
 * scopeUpcoming()/scopePast() are the single place this rule lives.
 */
class EventListing extends Model
{
    /** @use HasFactory<EventListingFactory> */
    use HasFactory;

    /** The timezone event dates/times are entered and shown in. */
    public const EVENT_TIMEZONE = 'Asia/Colombo';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /**
     * @var list<string>
     */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'created_by_user_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'image_path',
        'starts_at',
        'location',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
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
     * Public visibility rule (docs/database/10 §15), matching
     * `BlogPost::scopePublished()`.
     *
     * @param  Builder<EventListing>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)->where('published_at', '<=', now());
    }

    /**
     * The start of today in Colombo, as the wall-clock value `starts_at` is
     * stored in (`Y-m-d 00:00:00`).
     */
    public static function startOfEventToday(): string
    {
        return now(self::EVENT_TIMEZONE)->startOfDay()->format('Y-m-d H:i:s');
    }

    /**
     * Published events on today's date (Colombo) or later — an event stays
     * here for its whole day.
     *
     * @param  Builder<EventListing>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->published()->where('starts_at', '>=', self::startOfEventToday());
    }

    /**
     * Published events whose day ended before today (Colombo). Exactly the
     * complement of scopeUpcoming() among published events.
     *
     * @param  Builder<EventListing>  $query
     */
    public function scopePast(Builder $query): void
    {
        $query->published()->where('starts_at', '<', self::startOfEventToday());
    }

    /**
     * Same rule as scopeUpcoming(), for a single already-loaded item:
     * publicly visible and on today's date (Colombo) or later.
     */
    public function isUpcoming(): bool
    {
        return $this->isPubliclyVisible()
            && $this->starts_at->format('Y-m-d H:i:s') >= self::startOfEventToday();
    }

    /**
     * Same rule as scopePublished(), for a single already-loaded item.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }
}
