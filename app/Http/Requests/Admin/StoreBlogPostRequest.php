<?php

namespace App\Http\Requests\Admin;

use App\Models\BlogPost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreBlogPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BlogPost::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $image = config('uploads.blog_featured_image');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('blog_posts', 'slug')],
            'blog_category_id' => ['nullable', Rule::exists('blog_categories', 'id')],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'content' => ['required', 'string'],
            'featured_image' => ['nullable', File::types($image['extensions'])->max($image['max_kb'])],
            'featured_image_alt' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = round(config('uploads.blog_featured_image.max_kb') / 1024, 1);

        return [
            'featured_image.max' => "The featured image must not be larger than {$maxMb} MB.",
            'featured_image.mimes' => 'The featured image must be a JPG or PNG file.',
            'featured_image.uploaded' => "The featured image could not be uploaded. It may be larger than the {$maxMb} MB limit.",
            'slug.unique' => 'This slug is already used by another post. Please choose a different one.',
            'slug.alpha_dash' => 'The slug may only contain letters, numbers, dashes and underscores.',
        ];
    }
}
