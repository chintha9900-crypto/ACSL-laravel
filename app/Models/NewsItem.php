<?php

namespace App\Models;

use Database\Factories\NewsItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/database/10_CONTENT_SCHEMA.md §6 / docs/architecture/10 §3 (kept
 * separate from `BlogPost`, not merged). `content` is documented as
 * sanitised HTML (App\Support\HtmlSanitizer), the same convention as
 * `BlogPost` — Stage 1 has no admin write path yet, so nothing sanitises it
 * here; the article view renders it on that same assumption.
 */
class NewsItem extends Model
{
    /** @use HasFactory<NewsItemFactory> */
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
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * `BlogPost::scopePublished()` — a local scope, not a global one, so a
     * future admin query never has to remember to opt out of it.
     *
     * @param  Builder<NewsItem>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)->where('published_at', '<=', now());
    }

    /**
     * Same rule as scopePublished(), for a single already-loaded item (the
     * public article page route-model-binds by slug regardless of status).
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }
}
