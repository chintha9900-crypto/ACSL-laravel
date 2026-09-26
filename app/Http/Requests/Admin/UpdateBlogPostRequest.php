<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateBlogPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('post')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $image = config('uploads.blog_featured_image');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('blog_posts', 'slug')->ignore($this->route('post'))],
            'blog_category_id' => ['nullable', Rule::exists('blog_categories', 'id')],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'content' => ['required', 'string'],
            'featured_image' => ['nullable', File::types($image['extensions'])->max($image['max_kb'])],
            'featured_image_alt' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.unique' => 'This slug is already used by another post. Please choose a different one.',
            'slug.alpha_dash' => 'The slug may only contain letters, numbers, dashes and underscores.',
        ];
    }
}
