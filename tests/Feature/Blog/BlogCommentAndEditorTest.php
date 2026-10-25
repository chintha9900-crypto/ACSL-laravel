<?php

namespace Tests\Feature\Blog;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class BlogCommentAndEditorTest extends MysqlTestCase
{
    use CreatesReviewableApplications, CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function article(array $attributes = []): BlogPost
    {
        return BlogPost::factory()->published()->create($attributes);
    }

    private function comment(BlogPost $post, User $user, string $status, string $body = 'A comment'): BlogComment
    {
        return BlogComment::forceCreate([
            'blog_post_id' => $post->id, 'user_id' => $user->id, 'body' => $body, 'status' => $status,
            'moderated_at' => $status === 'pending' ? null : now(), 'moderated_by_user_id' => null,
        ]);
    }

    // --- comments: who may post ------------------------------------------------

    public function test_guests_see_a_sign_in_link_and_cannot_post(): void
    {
        $post = $this->article();

        $this->get(route('blog.show', $post->slug))->assertSee('href="'.route('login').'"', false)->assertSee('as a member to join the discussion')->assertDontSee('name="body"', false);
        $this->post(route('blog.comments.store', $post->slug), ['body' => 'Hello'])->assertRedirect(route('login'));
        $this->assertSame(0, BlogComment::query()->count());
    }

    public function test_an_active_member_posts_a_pending_comment_that_is_hidden_from_others(): void
    {
        $post = $this->article();
        $member = $this->member();

        $this->actingAs($member)->get(route('blog.show', $post->slug))->assertSee('name="body"', false);
        $this->actingAs($member)->post(route('blog.comments.store', $post->slug), ['body' => "  First line\nSecond line  "])
            ->assertRedirect(route('blog.show', $post->slug).'#comments');

        $comment = BlogComment::query()->firstOrFail();
        $this->assertSame('pending', $comment->status);
        $this->assertSame("First line\nSecond line", $comment->body);

        $this->actingAs($member)->get(route('blog.show', $post->slug))->assertSee('Awaiting approval')->assertSee('First line');
        $this->actingAs($this->member())->get(route('blog.show', $post->slug))->assertDontSee('First line')->assertDontSee('Awaiting approval');
        auth()->logout();
        $this->get(route('blog.show', $post->slug))->assertDontSee('First line');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staffRoles(): array
    {
        return ['admin' => ['admin'], 'editor' => ['editor'], 'dev' => ['dev']];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_accounts_cannot_post_comments(string $role): void
    {
        $post = $this->article();

        $this->actingAs(User::factory()->active()->create(['role' => $role]))->post(route('blog.comments.store', $post->slug), ['body' => 'Staff note'])->assertForbidden();
        $this->assertSame(0, BlogComment::query()->count());
    }

    public function test_an_inactive_member_and_a_draft_post_cannot_be_commented(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'suspended']))->post(route('blog.comments.store', $this->article()->slug), ['body' => 'x y'])->assertRedirect(route('login'));
        $this->actingAs($this->member())->post(route('blog.comments.store', BlogPost::factory()->create()->slug), ['body' => 'On a draft'])->assertNotFound();
        $this->assertSame(0, BlogComment::query()->count());
    }

    public function test_comment_validation_and_safe_rendering(): void
    {
        $post = $this->article();
        $member = $this->member();

        $this->actingAs($member)->post(route('blog.comments.store', $post->slug), ['body' => ''])->assertSessionHasErrors(['body' => 'Please write your comment.']);
        $this->actingAs($member)->post(route('blog.comments.store', $post->slug), ['body' => str_repeat('a', 2001)])->assertSessionHasErrors('body');

        $this->comment($post, $member, 'approved', '<script>alert("x")</script><b>bold?</b>');
        $html = $this->get(route('blog.show', $post->slug))->getContent();

        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&lt;b&gt;bold?&lt;/b&gt;', $html);
    }

    public function test_only_approved_comments_are_public_with_name_and_date_and_they_paginate(): void
    {
        $post = $this->article();
        $alice = User::factory()->active()->create(['name' => 'Alice Member']);
        $this->comment($post, $alice, 'approved', 'Approved words');
        $this->comment($post, $alice, 'rejected', 'Rejected words');
        $this->comment($post, $alice, 'pending', 'Pending words');

        $this->get(route('blog.show', $post->slug))->assertSee('Approved words')->assertSee('Alice Member')->assertSee('Comments (1)')
            ->assertDontSee('Rejected words')->assertDontSee('Pending words');
        $this->actingAs($alice)->get(route('blog.show', $post->slug))->assertSee('Not approved')->assertSee('Rejected words');

        foreach (range(1, 11) as $i) {
            $this->comment($post, $alice, 'approved', "Bulk {$i}");
        }
        auth()->logout();
        $this->get(route('blog.show', $post->slug))->assertSee('comments_page=2', false)->assertDontSee('Bulk 11');
        $this->get(route('blog.show', ['post' => $post->slug, 'comments_page' => 2]))->assertSee('Bulk 11');
    }

    // --- moderation --------------------------------------------------------------

    public function test_an_admin_approves_and_rejects_from_the_dashboard(): void
    {
        $post = $this->article(['title' => 'Moderated article']);
        $member = User::factory()->active()->create(['name' => 'Commenter']);
        $first = $this->comment($post, $member, 'pending', 'Please approve me');
        $second = $this->comment($post, $member, 'pending', 'Please reject me');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('Pending blog comments (2)')->assertSee('Please approve me')->assertSee('Moderated article');

        $this->actingAs($admin)->post(route('admin.blog-comments.approve', $first))->assertSessionHas('status', 'Comment approved.');
        $this->actingAs($admin)->post(route('admin.blog-comments.reject', $second))->assertSessionHas('status', 'Comment rejected.');

        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame($admin->id, $first->fresh()->moderated_by_user_id);
        $this->assertSame('rejected', $second->fresh()->status);
        $this->get(route('blog.show', $post->slug))->assertSee('Please approve me')->assertDontSee('Please reject me');

        $this->actingAs($admin)->post(route('admin.blog-comments.reject', $first))->assertSessionHas('warning', 'This comment has already been moderated.');
        $this->assertSame('approved', $first->fresh()->status);
        $this->actingAs($admin)->get(route('admin.blog-comments.index'))->assertOk()->assertSee('No comments are waiting for approval.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdminRoles(): array
    {
        return ['editor' => ['editor'], 'dev' => ['dev'], 'member' => ['member']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_admins_can_moderate(string $role): void
    {
        $comment = $this->comment($this->article(), User::factory()->active()->create(), 'pending');
        $user = User::factory()->active()->create(['role' => $role]);

        $this->actingAs($user)->get(route('admin.blog-comments.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.blog-comments.approve', $comment))->assertForbidden();
        $this->actingAs($user)->post(route('admin.blog-comments.reject', $comment))->assertForbidden();
        $this->assertSame('pending', $comment->fresh()->status);

        if ($role === 'editor') {
            $this->actingAs($user)->get(route('editor.dashboard'))->assertDontSee('Pending blog comments');
        }
    }

    public function test_guests_cannot_moderate(): void
    {
        $comment = $this->comment($this->article(), User::factory()->active()->create(), 'pending');

        $this->post(route('admin.blog-comments.approve', $comment))->assertRedirect(route('login'));
        $this->assertSame('pending', $comment->fresh()->status);
    }

    // --- admin editor --------------------------------------------------------------

    private function editPayload(BlogPost $post, array $overrides = []): array
    {
        return ['title' => $post->title, 'slug' => $post->slug, 'content' => $post->content, ...$overrides];
    }

    public function test_the_editor_offers_formatting_controls_image_preview_and_size_warning(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.blog.create'))->assertOk()->getContent();

        foreach (['Heading 2', 'Heading 3', 'Bold', 'Italic', 'Bulleted list', 'Numbered list', 'Insert link', 'Align centre', 'Justify'] as $control) {
            $this->assertStringContainsString('aria-label="'.$control.'"', $html);
        }
        $this->assertStringContainsString('name="content"', $html, 'The textarea stays as the no-JS fallback.');
        $this->assertStringContainsString('data-max-bytes="'.(config('uploads.blog_featured_image.max_kb') * 1024).'"', $html);
        $this->assertStringContainsString('data-image-preview hidden', $html);
        $this->assertStringContainsString('URL.createObjectURL(file)', $html);
        $this->assertStringContainsString("'image/jpeg', 'image/png'", $html);
        $this->assertStringContainsString('which is larger than the', $html);
        $this->assertStringContainsString('Remove selected image', $html);

        // Lists the browser nests inside a paragraph are moved out before saving,
        // so the sanitizer never has to split them (which would add empty paragraphs).
        $this->assertStringContainsString('function normalize()', $html);
        $this->assertStringContainsString("textarea.form.addEventListener('submit', function () { normalize(); sync(); });", $html);
        $this->assertStringContainsString('data-rte-toolbar hidden', $html, 'The toolbar is shown by the script; without JavaScript the textarea is used.');
    }

    public function test_the_edit_page_loads_existing_formatted_content_into_the_editor_field(): void
    {
        $post = $this->article(['content' => '<h2>Existing</h2><p style="text-align: center;"><strong>Bold</strong></p><ul><li>Item</li></ul>']);

        $html = $this->actingAs($this->admin())->get(route('admin.blog.edit', $post))->assertOk()->getContent();

        $this->assertStringContainsString(e('<h2>Existing</h2><p style="text-align: center;"><strong>Bold</strong></p><ul><li>Item</li></ul>'), $html);
        $this->assertStringContainsString('data-rte-editor hidden contenteditable="true"', $html);
    }

    public function test_rich_text_is_stored_sanitised_with_alignment_and_renders(): void
    {
        $post = $this->article(['content' => '<p>Old</p>']);
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.blog.update', $post), $this->editPayload($post, [
            'content' => '<h2>Heading</h2><p style="text-align: right; color: red">Aligned</p><ol><li>Item</li></ol><p><a href="javascript:alert(1)">bad</a><a href="https://example.test">good</a></p><script>x</script>',
        ]))->assertSessionHasNoErrors();

        $stored = $post->fresh()->content;
        $this->assertSame('<h2>Heading</h2><p style="text-align: right;">Aligned</p><ol><li>Item</li></ol><p><a>bad</a><a href="https://example.test">good</a></p>', $stored);
        $this->get(route('blog.show', $post->slug))->assertSee('<p style="text-align: right;">Aligned</p>', false);
    }

    public function test_tags_are_saved_from_a_comma_list_and_shown(): void
    {
        $post = $this->article();

        $this->actingAs($this->admin())->patch(route('admin.blog.update', $post), $this->editPayload($post, ['tags' => 'Pilots, Training , pilots,,']))->assertSessionHasNoErrors();

        $this->assertSame(['Pilots', 'Training'], $post->fresh()->tags->pluck('name')->all());
        $this->assertSame(2, BlogTag::query()->count());
        $this->get(route('blog.show', $post->slug))->assertSee('Training');
    }

    public function test_featuring_a_post_replaces_the_previous_featured_post_and_drafts_cannot_be_featured(): void
    {
        $admin = $this->admin();
        $first = $this->article(['is_featured' => true]);
        $second = $this->article();
        $draft = BlogPost::factory()->create();

        $this->actingAs($admin)->patch(route('admin.blog.update', $second), $this->editPayload($second, ['is_featured' => '1']))->assertSessionHasNoErrors();
        $this->assertFalse($first->fresh()->is_featured);
        $this->assertTrue($second->fresh()->is_featured);

        $this->actingAs($admin)->patch(route('admin.blog.update', $draft), $this->editPayload($draft, ['is_featured' => '1']))
            ->assertSessionHasErrors(['is_featured' => 'Only a published post can be featured. Publish it first.']);

        $this->actingAs($admin)->post(route('admin.blog.unpublish', $second));
        $this->assertFalse($second->fresh()->is_featured);
    }

    public function test_server_side_image_validation_rejects_wrong_types_oversized_and_failed_uploads(): void
    {
        $post = $this->article();
        $admin = $this->admin();
        $maxMb = round(config('uploads.blog_featured_image.max_kb') / 1024, 1);

        $this->actingAs($admin)->patch(route('admin.blog.update', $post), $this->editPayload($post, ['featured_image' => $this->pdfUpload('not-image.pdf')]))
            ->assertSessionHasErrors(['featured_image' => 'The featured image must be a JPG or PNG file.']);

        config(['uploads.blog_featured_image.max_kb' => 1]);
        $big = $this->realUpload('big.png', $this->pngContent().str_repeat("\0", 4096), 'image/png');
        $this->actingAs($admin)->patch(route('admin.blog.update', $post), $this->editPayload($post, ['featured_image' => $big]))
            ->assertSessionHasErrors(['featured_image' => 'The featured image must not be larger than 0 MB.']);
        config(['uploads.blog_featured_image.max_kb' => (int) ($maxMb * 1024)]);

        $failed = new UploadedFile($this->pngUpload()->getRealPath(), 'x.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);
        $this->actingAs($admin)->patch(route('admin.blog.update', $post), $this->editPayload($post, ['featured_image' => $failed]))
            ->assertSessionHasErrors(['featured_image' => "The featured image could not be uploaded. It may be larger than the {$maxMb} MB limit."]);

        $this->assertNull($post->fresh()->featured_image_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_valid_image_is_stored_on_the_public_disk_and_displayed(): void
    {
        $post = $this->article();

        $this->actingAs($this->admin())->patch(route('admin.blog.update', $post), $this->editPayload($post, ['featured_image' => $this->pngUpload('cover.png')]))->assertSessionHasNoErrors();

        $path = $post->fresh()->featured_image_path;
        $this->assertMatchesRegularExpression('#^blog/[0-9a-z]{26}\.png$#', $path);
        Storage::disk('public')->assertExists($path);
        $this->get(route('blog.show', $post->slug))->assertSee(Storage::disk('public')->url($path), false);
    }
}
