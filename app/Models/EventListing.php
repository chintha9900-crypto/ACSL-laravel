<?php

namespace App\Models;

use Database\Factories\EventListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/database/10_CONTENT_SCHEMA.md §7. `content` rendering (plain text vs.
 * HTML) is an open decision (architecture `10` §4, OD #19) — the reference
 * app rendered it as plain preformatted text, so the article view follows
 * that literally rather than guessing at HTML support.
 *
 * Past events are not filtered out of the public listing: whether to show
 * only upcoming events is explicitly an unresolved query-level concern
 * (docs/database/10 §7), not something this stage decides.
 */
class EventListing extends Model
{
    /** @use HasFactory<EventListingFactory> */
    use HasFactory;

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
     * Same rule as scopePublished(), for a single already-loaded item.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }
}
