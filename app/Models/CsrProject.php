<?php

namespace App\Models;

use Database\Factories\CsrProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single CSR (Corporate Social Responsibility) project shown on the public
 * `/csr` page. Same draft/published + `published_at` shape as `NewsItem`/
 * `EventListing`; `content` is documented as sanitised HTML, the same
 * convention as those models — Stage 1 has no admin write path yet, so
 * nothing sanitises it here.
 */
class CsrProject extends Model
{
    /** @use HasFactory<CsrProjectFactory> */
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
     * Public visibility rule, matching `NewsItem::scopePublished()` — a
     * local scope, not a global one, so a future admin query never has to
     * remember to opt out of it.
     *
     * @param  Builder<CsrProject>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)->where('published_at', '<=', now());
    }

    /**
     * Same rule as scopePublished(), for a single already-loaded project
     * (the public project page route-model-binds by slug regardless of
     * status).
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    /**
     * The listing page's preview text when there are more than 5 projects
     * (see `CsrController::index()`): the first `$lines` non-blank lines of
     * `content`, with HTML tags stripped so a `<p>`-per-line body collapses
     * to plain text instead of unbalanced markup.
     */
    public function previewLines(int $lines = 10): string
    {
        $plain = str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $this->content);
        $plain = trim(strip_tags($plain));

        $allLines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $plain),
            fn (string $line): bool => trim($line) !== ''
        ));

        return implode("\n", array_slice($allLines, 0, $lines));
    }
}
