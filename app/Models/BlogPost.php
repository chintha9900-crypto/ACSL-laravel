<?php

namespace App\Models;

use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Homepage "Featured insights & events" article slots (`homepage_position`):
     * 1 = middle column, 2 = right column. Independent of `is_featured`.
     *
     * @var array<int, string>
     */
    public const HOMEPAGE_POSITIONS = [
        1 => 'Homepage position 1 (middle column)',
        2 => 'Homepage position 2 (right column)',
    ];

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
            'is_featured' => 'boolean',
            'homepage_position' => 'integer',
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
    /**
     * @return BelongsToMany<BlogTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag')->orderBy('name');
    }

    /**
     * @return HasMany<BlogComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(BlogComment::class);
    }

    /**
     * The post shown at the top of `/blog`: the published post an admin marked
     * Featured, otherwise the newest published post.
     */
    public static function forListing(): ?self
    {
        return self::query()->published()->where('is_featured', true)->first()
            ?? self::query()->published()->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    /**
     * The published posts in homepage positions 1 and 2, in position order,
     * with their category loaded. Drafts and scheduled posts holding a
     * position are left out; each post can hold only one position.
     *
     * @return Collection<int, BlogPost>
     */
    public static function onHomepage(): Collection
    {
        return self::query()
            ->published()
            ->whereIn('homepage_position', array_keys(self::HOMEPAGE_POSITIONS))
            ->with('category:id,name,slug')
            ->orderBy('homepage_position')
            ->limit(count(self::HOMEPAGE_POSITIONS))
            ->get(['id', 'blog_category_id', 'title', 'slug', 'excerpt', 'content', 'featured_image_path', 'featured_image_alt', 'published_at', 'homepage_position']);
    }

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
