<?php

namespace App\Http\Requests\Admin;

use App\Models\EducationArticle;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * Create an Education article (always saved as a draft). Status,
 * published_at, slug and the user columns are never accepted from the form.
 */
class StoreEducationArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EducationArticle::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $image = config('uploads.education_image');

        return [
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:200000'],
            'display_column' => ['required', 'string', Rule::in(array_keys(EducationArticle::COLUMNS))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'featured_image' => ['nullable', File::types($image['extensions'])->max($image['max_kb'])],
            'featured_image_alt' => ['nullable', 'string', 'max:255'],
            'button_label' => ['nullable', 'string', 'max:100', 'required_with:button_url'],
            'button_url' => ['nullable', 'required_with:button_label', ...self::buttonUrlRules()],
            'secondary_button_label' => ['nullable', 'string', 'max:100', 'required_with:secondary_button_url'],
            'secondary_button_url' => ['nullable', 'required_with:secondary_button_label', ...self::buttonUrlRules()],
        ];
    }

    /**
     * A card button may link to a page on this site (a path starting with a
     * single `/`) or to an https address with a real host. Nothing else —
     * no `javascript:`, `data:`, protocol-relative `//host` or plain http.
     *
     * @return list<mixed>
     */
    public static function buttonUrlRules(): array
    {
        return [
            'string',
            'max:2048',
            function (string $attribute, mixed $value, Closure $fail): void {
                $url = (string) $value;

                $isSitePath = preg_match('#^/(?!/)[A-Za-z0-9\-._~/%?=&]*$#', $url) === 1;
                $parts = parse_url($url);
                $isHttps = $parts !== false
                    && strtolower($parts['scheme'] ?? '') === 'https'
                    && str_contains((string) ($parts['host'] ?? ''), '.')
                    && filter_var($url, FILTER_VALIDATE_URL) !== false;

                if (! $isSitePath && ! $isHttps) {
                    $fail('The button link must be a page on this site (starting with /) or a full https:// address.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'button_label.required_with' => 'Add a label for this button, or clear its link.',
            'button_url.required_with' => 'Add a link for this button, or clear its label.',
            'secondary_button_label.required_with' => 'Add a label for the second button, or clear its link.',
            'secondary_button_url.required_with' => 'Add a link for the second button, or clear its label.',
        ];
    }
}
