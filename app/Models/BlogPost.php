<?php

namespace App\Models;

use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/database/10_CONTENT_SCHEMA.md §3 / docs/architecture/10 §2. `content`
 * is allow-list-sanitised HTML on save (see App\Support\HtmlSanitizer), never
 * trusted as-is because an admin authored it. `published_at` is stamped by
 * SaveBlogPost on any transition into `published` — never set directly here.
 */
class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
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
        'author_id',
        'blog_category_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image_path',
        'featured_image_alt',
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
     * @return BelongsTo<BlogCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Public visibility rule (docs/architecture/10 §9) — a local scope,
     * matching this codebase's existing convention (e.g.
     * MembershipCategory::scopeAcceptingApplications) rather than a global
     * scope, so admin queries never have to remember to opt out of it.
     *
     * @param  Builder<BlogPost>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)->where('published_at', '<=', now());
    }

    /**
     * Same rule as scopePublished(), for a single already-loaded post (the
     * public article page route-model-binds by slug regardless of status,
     * so it checks this before showing anything).
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }
}
