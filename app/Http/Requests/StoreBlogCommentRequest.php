<?php

namespace App\Http\Requests;

use App\Models\BlogComment;
use Illuminate\Foundation\Http\FormRequest;

class StoreBlogCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BlogComment::class) ?? false;
    }

    /**
     * Plain text only: control characters are removed and surrounding space
     * trimmed. Output is always escaped, so markup is shown as text, never run.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim(preg_replace('/[^\P{C}\n\t]/u', '', $this->input('body')) ?? '')]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Please write your comment.',
            'body.min' => 'Your comment is too short.',
            'body.max' => 'Your comment must not be longer than 2,000 characters.',
        ];
    }
}
