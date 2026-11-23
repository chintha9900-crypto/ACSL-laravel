<?php

namespace App\Actions\Content;

use App\Exceptions\EducationArticleNotSavedException;
use App\Models\EducationArticle;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates/updates an Education article's editable fields. Never touches
 * `status`/`published_at` (a new article is a draft; Publish/Unpublish are
 * separate actions), so saving never publishes. `content` is sanitised with
 * images allowed only from the Education inline-image folder. Inline images
 * dropped from the content are deleted after the transaction commits, and
 * only if no other article still uses them. A save Eloquent reports as not
 * performed throws EducationArticleNotSavedException.
 */
class SaveEducationArticle
{
    public function __construct(private DeleteUnreferencedEducationImages $deleteImages) {}

    /**
     * @param  array<string, mixed>  $data  Validated fields, without file inputs.
     */
    public function create(array $data, User $user): EducationArticle
    {
        $article = new EducationArticle($this->prepare($data));
        $saved = $article->forceFill([
            'slug' => $this->uniqueSlug((string) $data['title']),
            'status' => EducationArticle::STATUS_DRAFT,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ])->save();

        if (! $saved) {
            throw new EducationArticleNotSavedException('The article could not be created.');
        }

        return $article;
    }

    /**
     * The slug is kept as first created, so an article's address is stable.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(EducationArticle $article, array $data, User $user): EducationArticle
    {
        $before = EducationArticle::inlineImagePaths($article->content);

        $saved = $article->fill($this->prepare($data))->forceFill(['updated_by_user_id' => $user->id])->save();

        if (! $saved) {
            throw new EducationArticleNotSavedException('The article could not be updated.');
        }

        $removed = array_values(array_diff($before, EducationArticle::inlineImagePaths($article->content)));

        if ($removed !== []) {
            DB::afterCommit(fn () => $this->deleteImages->handle($removed));
        }

        return $article;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data): array
    {
        unset($data['slug'], $data['status'], $data['published_at'], $data['featured_image'], $data['remove_featured_image']);

        $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''), EducationArticle::inlineImageUrlPrefix());

        foreach (['featured_image_alt', 'button_label', 'button_url', 'secondary_button_label', 'secondary_button_url'] as $optional) {
            if (array_key_exists($optional, $data) && trim((string) $data[$optional]) === '') {
                $data[$optional] = null;
            }
        }

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 200, '') ?: 'article';
        $slug = $base;

        for ($suffix = 2; EducationArticle::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
