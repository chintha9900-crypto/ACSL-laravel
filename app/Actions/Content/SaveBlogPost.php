<?php

namespace App\Actions\Content;

use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveBlogPost
{
    /**
     * Create a post as a draft. Content is allow-list sanitised on save;
     * `tags` (comma-separated names) are synced.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $author): BlogPost
    {
        return DB::transaction(function () use ($data, $author): BlogPost {
            $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));
            $data['author_id'] = $author->id;
            $data['status'] = BlogPost::STATUS_DRAFT;

            $post = BlogPost::create($data);
            $this->syncTags($post, $data['tags'] ?? null);

            return $post;
        });
    }

    /**
     * Update a post. With `is_featured` present, the post becomes (or stops
     * being) the one featured post — any other featured post is cleared in the
     * same transaction; the unique `featured_key` is the backstop.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(BlogPost $post, array $data): BlogPost
    {
        return DB::transaction(function () use ($post, $data): BlogPost {
            $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));
            $post->fill($data)->save();

            if (array_key_exists('is_featured', $data)) {
                $featured = (bool) $data['is_featured'];
                if ($featured) {
                    BlogPost::query()->where('is_featured', true)->whereKeyNot($post->id)->update(['is_featured' => false]);
                }
                $post->forceFill(['is_featured' => $featured])->save();
            }

            $this->syncTags($post, $data['tags'] ?? null);

            return $post;
        });
    }

    /**
     * Comma-separated tag names → tags (created when new, matched by slug).
     */
    private function syncTags(BlogPost $post, mixed $tags): void
    {
        if ($tags === null) {
            return;
        }

        $ids = collect(explode(',', (string) $tags))
            ->map(fn (string $name): string => Str::limit(trim(preg_replace('/\s+/', ' ', $name) ?? ''), 60, ''))
            ->filter(fn (string $name): bool => $name !== '' && Str::slug($name) !== '')
            ->unique(fn (string $name): string => Str::slug($name))
            ->take(15)
            ->map(fn (string $name): int => BlogTag::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->id)
            ->values()
            ->all();

        $post->tags()->sync($ids);
    }
}
