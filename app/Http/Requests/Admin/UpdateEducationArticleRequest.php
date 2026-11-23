<?php

namespace App\Http\Requests\Admin;

/**
 * Same rules as creating, plus "Remove current image". Saving never changes
 * the article's status.
 */
class UpdateEducationArticleRequest extends StoreEducationArticleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('article')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'remove_featured_image' => ['nullable', 'boolean'],
        ];
    }
}
