<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_it_keeps_allowed_formatting_tags(): void
    {
        $html = '<p>Hello <strong>world</strong>, this is <em>great</em>.</p><h2>A heading</h2><ul><li>One</li><li>Two</li></ul>';

        $this->assertSame($html, HtmlSanitizer::clean($html));
    }

    public function test_it_strips_script_tags_entirely_including_their_content(): void
    {
        $clean = HtmlSanitizer::clean('<p>Safe</p><script>alert("xss")</script>');

        $this->assertSame('<p>Safe</p>', $clean);
    }

    public function test_it_removes_inline_event_handler_attributes(): void
    {
        $clean = HtmlSanitizer::clean('<p onclick="alert(1)">Click me</p>');

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringContainsString('Click me', $clean);
    }

    public function test_it_removes_a_javascript_url_but_keeps_the_link_text(): void
    {
        $clean = HtmlSanitizer::clean('<a href="javascript:alert(1)">Click</a>');

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('Click', $clean);
    }

    public function test_it_keeps_a_safe_http_link(): void
    {
        $clean = HtmlSanitizer::clean('<a href="https://example.test/page">Link</a>');

        $this->assertStringContainsString('href="https://example.test/page"', $clean);
    }

    public function test_it_unwraps_disallowed_but_harmless_tags_keeping_their_text(): void
    {
        $clean = HtmlSanitizer::clean('<div><span>Hello</span> world</div>');

        $this->assertStringNotContainsString('<div>', $clean);
        $this->assertStringNotContainsString('<span>', $clean);
        $this->assertStringContainsString('Hello', $clean);
        $this->assertStringContainsString('world', $clean);
    }

    public function test_it_removes_an_iframe_entirely(): void
    {
        $clean = HtmlSanitizer::clean('<p>Before</p><iframe src="https://evil.test"></iframe><p>After</p>');

        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringContainsString('Before', $clean);
        $this->assertStringContainsString('After', $clean);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(''));
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }
}
