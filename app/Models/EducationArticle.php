<?php

namespace App\Models;

use Database\Factories\EducationArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A topic card on the public Education page and the full article behind it.
 * `content` is sanitised HTML that may contain images uploaded through the
 * Education editor (and only those — see INLINE_IMAGE_DIRECTORY).
 */
class EducationArticle extends Model
{
    /** @use HasFactory<EducationArticleFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PUBLISHED => 'Published',
    ];

    public const COLUMN_LEFT = 'left';

    public const COLUMN_RIGHT = 'right';

    /**
     * @var array<string, string>
     */
    public const COLUMNS = [
        self::COLUMN_LEFT => 'Left column',
        self::COLUMN_RIGHT => 'Right column',
    ];

    /** Public-disk folder for featured images. */
    public const FEATURED_IMAGE_DIRECTORY = 'education/featured';

    /** Public-disk folder for images inserted into article content. */
    public const INLINE_IMAGE_DIRECTORY = 'education/inline';

    /**
     * `status`, `published_at`, `slug` and the user columns are set only by
     * the save/publish actions, never from form input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'short_description',
        'content',
        'featured_image_alt',
        'display_column',
        'sort_order',
        'button_label',
        'button_url',
        'secondary_button_label',
        'secondary_button_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @param  Builder<EducationArticle>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Column order first, then display order, then oldest first.
     *
     * @param  Builder<EducationArticle>  $query
     */
    public function scopeInDisplayOrder(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * The URL path prefix under which inline images are served; the only
     * image source HtmlSanitizer keeps for Education content.
     */
    public static function inlineImageUrlPrefix(): string
    {
        return '/storage/'.self::INLINE_IMAGE_DIRECTORY.'/';
    }

    /**
     * Public-disk paths of the inline images referenced by `$html`.
     *
     * @return list<string>
     */
    public static function inlineImagePaths(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }

        preg_match_all('#'.preg_quote(self::inlineImageUrlPrefix(), '#').'([0-9a-z]{26}\.(?:jpg|png))#', $html, $matches);

        return array_values(array_unique(array_map(
            fn (string $file) => self::INLINE_IMAGE_DIRECTORY.'/'.$file,
            $matches[1],
        )));
    }

    public function columnLabel(): string
    {
        return self::COLUMNS[$this->display_column] ?? $this->display_column;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
