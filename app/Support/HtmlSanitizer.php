<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * A small, dependency-free allow-list HTML sanitizer
 * (docs/architecture/10_CONTENT_ARCHITECTURE.md §5): every rich-text field is
 * sanitised on save, never trusted merely because an admin authored it. No
 * package is installed for this (none is approved/available for this task) —
 * this uses only PHP's built-in DOM extension, walking the parsed tree and
 * keeping only an explicit allow-list of tags/attributes.
 */
class HtmlSanitizer
{
    /**
     * Tag => its allowed attributes.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TAGS = [
        'p' => ['style'],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => ['style'],
        'blockquote' => ['style'],
        'h2' => ['style'],
        'h3' => ['style'],
        'h4' => ['style'],
        'a' => ['href'],
    ];

    /**
     * Removed entirely, including their content — never just unwrapped.
     *
     * @var list<string>
     */
    private const STRIP_ENTIRELY = [
        'script', 'style', 'iframe', 'object', 'embed', 'form',
        'input', 'button', 'svg', 'math', 'link', 'meta', 'noscript',
    ];

    /**
     * @param  string|null  $allowImagesUnder  Opt-in (Education only): a URL path prefix such as
     *                                         `/storage/education/inline/`. When given, `<img>` is kept only
     *                                         if its source is a file directly under that prefix on this site
     *                                         (rewritten to the bare path) and only `src`/`alt` survive.
     *                                         Any other image is removed. When null (the default, used by
     *                                         every other caller) behaviour is exactly as before: no images.
     */
    public static function clean(string $html, ?string $allowImagesUnder = null): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><html><body>'.$html.'</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        self::sanitizeChildren($body, $dom, $allowImagesUnder);

        $output = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Safe HTML for showing or re-editing content that may predate the rich-text
     * editor. Content with allow-listed tags is simply cleaned. Anything else is
     * plain text: `<`/`>` stay literal, and blank lines and line breaks become
     * paragraphs and `<br>`, which would otherwise collapse as HTML.
     */
    public static function forDisplay(string $content, ?string $allowImagesUnder = null): string
    {
        $content = trim($content);
        $tags = implode('|', array_keys(self::ALLOWED_TAGS)).($allowImagesUnder !== null ? '|img' : '');

        if (preg_match('/<\/?(?:'.$tags.')(?:\s+[a-z-]+\s*=[^<>]*)?\s*\/?>/i', $content) === 1) {
            return self::clean($content, $allowImagesUnder);
        }

        $paragraphs = preg_split('/\R\s*\R/u', str_replace(['<', '>'], ['&lt;', '&gt;'], $content)) ?: [];

        return self::clean(implode('', array_map(
            fn (string $paragraph): string => $paragraph === '' ? '' : '<p>'.nl2br($paragraph, false).'</p>',
            $paragraphs,
        )));
    }

    private static function sanitizeChildren(DOMNode $node, DOMDocument $dom, ?string $allowImagesUnder = null): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::STRIP_ENTIRELY, true)) {
                    $node->removeChild($child);

                    continue;
                }

                if ($tag === 'img' && $allowImagesUnder !== null) {
                    self::keepLocalImageOrRemove($child, $node, $allowImagesUnder);

                    continue;
                }

                if (! array_key_exists($tag, self::ALLOWED_TAGS)) {
                    // Not on the allow-list, but not inherently dangerous
                    // either (e.g. <div>, <span>, <table>): unwrap it —
                    // sanitise and keep its children, drop the tag itself.
                    self::sanitizeChildren($child, $dom, $allowImagesUnder);

                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child);
                    }

                    $node->removeChild($child);

                    continue;
                }

                self::stripDisallowedAttributes($child, self::ALLOWED_TAGS[$tag]);
                self::sanitizeChildren($child, $dom, $allowImagesUnder);
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * Keeps an `<img>` only when its source is a file directly under the
     * allowed path prefix on this site — relative, or absolute http(s) on
     * the app's own host — with a plain file name (no query, fragment or
     * sub-folder). The source is rewritten to that bare path, and only `src`
     * and a plain-text `alt` (max 255 characters) remain. Anything else —
     * external hosts, `data:`, `javascript:`, other folders — is removed.
     */
    private static function keepLocalImageOrRemove(DOMElement $image, DOMNode $parent, string $allowImagesUnder): void
    {
        $path = self::localImagePath(trim($image->getAttribute('src')), $allowImagesUnder);

        if ($path === null) {
            $parent->removeChild($image);

            return;
        }

        $alt = mb_substr(trim(preg_replace('/\s+/u', ' ', $image->getAttribute('alt')) ?? ''), 0, 255);

        foreach (iterator_to_array($image->attributes ?? []) as $attribute) {
            $image->removeAttribute($attribute->name);
        }

        while ($image->firstChild !== null) {
            $image->removeChild($image->firstChild);
        }

        $image->setAttribute('src', $path);
        $image->setAttribute('alt', $alt);
    }

    private static function localImagePath(string $src, string $allowImagesUnder): ?string
    {
        $parts = parse_url($src);

        if ($src === '' || $parts === false || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['host']) || isset($parts['scheme'])) {
            $scheme = strtolower($parts['scheme'] ?? '');
            $host = strtolower($parts['host'] ?? '');
            $ownHosts = array_filter([
                strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
                app()->bound('request') ? strtolower(request()->getHost()) : '',
            ]);

            if (! in_array($scheme, ['http', 'https'], true) || ! in_array($host, $ownHosts, true)) {
                return null;
            }
        }

        $path = $parts['path'] ?? '';

        return preg_match('#^'.preg_quote($allowImagesUnder, '#').'[0-9a-z]{26}\.(?:jpg|png)$#', $path) === 1 ? $path : null;
    }

    /**
     * @param  list<string>  $allowedAttributes
     */
    private static function stripDisallowedAttributes(DOMElement $element, array $allowedAttributes): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->name);

            if (! in_array($name, $allowedAttributes, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'href' && ! self::isSafeUrl($attribute->value)) {
                $element->removeAttribute($attribute->name);
            }

            if ($name === 'style') {
                $alignment = self::textAlignment($attribute->value);
                $alignment === null
                    ? $element->removeAttribute($attribute->name)
                    : $element->setAttribute('style', 'text-align: '.$alignment.';');
            }
        }
    }

    /**
     * The only inline style ever kept: a `text-align` of left/center/right/
     * justify (from the editor's alignment buttons). Everything else in the
     * attribute is discarded, so no other CSS can be injected.
     */
    private static function textAlignment(string $style): ?string
    {
        return preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right|justify)\s*(?:;|$)/i', $style, $match) === 1
            ? strtolower($match[1])
            : null;
    }

    /**
     * Allow-list of URL shapes: relative, in-page, or explicit http(s) — never
     * `javascript:`, `data:` or any other scheme.
     */
    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        if ($url[0] === '#' || $url[0] === '/') {
            return true;
        }

        return (bool) preg_match('/^https?:\/\//i', $url);
    }
}
