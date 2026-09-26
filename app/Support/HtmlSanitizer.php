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
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
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

    public static function clean(string $html): string
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

        self::sanitizeChildren($body, $dom);

        $output = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private static function sanitizeChildren(DOMNode $node, DOMDocument $dom): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::STRIP_ENTIRELY, true)) {
                    $node->removeChild($child);

                    continue;
                }

                if (! array_key_exists($tag, self::ALLOWED_TAGS)) {
                    // Not on the allow-list, but not inherently dangerous
                    // either (e.g. <div>, <span>, <table>): unwrap it —
                    // sanitise and keep its children, drop the tag itself.
                    self::sanitizeChildren($child, $dom);

                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child);
                    }

                    $node->removeChild($child);

                    continue;
                }

                self::stripDisallowedAttributes($child, self::ALLOWED_TAGS[$tag]);
                self::sanitizeChildren($child, $dom);
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
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
        }
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
