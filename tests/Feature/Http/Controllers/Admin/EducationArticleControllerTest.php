<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Content\UpdateEducationFeaturedImage;
use App\Models\EducationArticle;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class EducationArticleControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Air Traffic Control Careers',
            'short_description' => 'How to become an air traffic controller.',
            'content' => '<h2>Overview</h2><p>The full article.</p>',
            'display_column' => 'left',
            'sort_order' => 6,
            'featured_image_alt' => '',
            'button_label' => '',
            'button_url' => '',
            'secondary_button_label' => '',
            'secondary_button_url' => '',
            ...$overrides,
        ];
    }

    private function inlineImage(): string
    {
        $file = strtolower((string) Str::ulid()).'.png';
        Storage::disk('public')->put(EducationArticle::INLINE_IMAGE_DIRECTORY.'/'.$file, 'png');

        return EducationArticle::INLINE_IMAGE_DIRECTORY.'/'.$file;
    }

    private function imageTag(string $path, string $alt = 'An image'): string
    {
        return '<p><img src="/storage/'.$path.'" alt="'.$alt.'"></p>';
    }

    private function newest(): EducationArticle
    {
        return EducationArticle::query()->latest('id')->firstOrFail();
    }

    // --- authorization ------------------------------------------------------------

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function routes(EducationArticle $article): array
    {
        return [
            ['get', route('admin.education.index')],
            ['get', route('admin.education.create')],
            ['post', route('admin.education.store')],
            ['post', route('admin.education.images.store')],
            ['get', route('admin.education.show', $article)],
            ['get', route('admin.education.edit', $article)],
            ['patch', route('admin.education.update', $article)],
            ['delete', route('admin.education.destroy', $article)],
            ['post', route('admin.education.publish', $article)],
            ['post', route('admin.education.unpublish', $article)],
        ];
    }

    public function test_guests_are_sent_to_login_from_every_management_route(): void
    {
        $article = EducationArticle::factory()->create();

        foreach ($this->routes($article) as [$method, $url]) {
            $this->{$method}($url, $this->payload())->assertRedirect(route('login'));
        }
    }

    public function test_members_and_dev_users_are_refused_every_management_route(): void
    {
        $article = EducationArticle::factory()->create();
        $count = EducationArticle::query()->count();

        foreach ([$this->member(), $this->dev()] as $user) {
            foreach ($this->routes($article) as [$method, $url]) {
                $this->actingAs($user)->{$method}($url, [...$this->payload(), 'image' => $this->pngUpload()])->assertForbidden();
            }
        }

        $this->assertModelExists($article);
        $this->assertSame($count, EducationArticle::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_inactive_editor_cannot_reach_management(): void
    {
        $editor = User::factory()->editor()->create(['status' => 'suspended']);

        $this->actingAs($editor)->get(route('admin.education.index'))->assertRedirect(route('login'));
    }

    /**
     * Runs every management action as `$user`, recording the outcomes.
     *
     * @return array<string, mixed>
     */
    private function exerciseEveryAction(User $user): array
    {
        $disk = Storage::disk('public');
        $out = [];
        $this->actingAs($user);

        $dashboard = $user->role === 'admin' ? route('admin.dashboard') : route('editor.dashboard');
        $out['dashboard link'] = str_contains($this->get($dashboard)->getContent(), 'href="'.route('admin.education.index').'"');
        foreach (['', 'draft', 'published'] as $status) {
            $out["index {$status}"] = $this->get(route('admin.education.index', array_filter(['status' => $status])))->status();
        }
        $out['create page'] = $this->get(route('admin.education.create'))->status();

        $upload = $this->post(route('admin.education.images.store'), ['image' => $this->pngUpload()], ['Accept' => 'application/json']);
        $out['inline upload'] = $upload->status();
        $inlinePath = ltrim(str_replace('/storage/', '', (string) $upload->json('url')), '/');

        $out['store'] = $this->post(route('admin.education.store'), $this->payload([
            'title' => 'Equivalence '.$user->role,
            'content' => '<p>Body</p><p><img src="'.$upload->json('url').'" alt="Inline"></p>',
            'featured_image' => $this->pngUpload(),
            'featured_image_alt' => 'Featured',
            'display_column' => 'right',
            'button_label' => 'Join',
            'button_url' => '/membership/apply',
        ]))->status();
        $article = $this->newest();
        $firstFeatured = $article->featured_image_path;
        $out['saved as draft'] = $article->status;
        $out['column'] = $article->display_column;
        $out['inline kept'] = str_contains($article->content, '/storage/'.$inlinePath);
        $out['preview draft'] = $this->get(route('admin.education.show', $article))->status();
        $out['public draft'] = $this->get(route('education.show', $article))->status();
        $out['edit page'] = $this->get(route('admin.education.edit', $article))->status();

        $out['replace featured'] = $this->patch(route('admin.education.update', $article), $this->payload(['title' => $article->title, 'content' => $article->content, 'featured_image' => $this->pngUpload('new.png')]))->status();
        $out['old featured deleted'] = ! $disk->exists($firstFeatured);
        $out['still draft after save'] = $article->fresh()->status;

        $out['publish'] = $this->post(route('admin.education.publish', $article))->status();
        $out['public after publish'] = $this->get(route('education.show', $article))->status();
        $out['unpublish'] = $this->post(route('admin.education.unpublish', $article))->status();
        $out['public after unpublish'] = $this->get(route('education.show', $article))->status();

        $out['remove featured'] = $this->patch(route('admin.education.update', $article), $this->payload(['title' => $article->title, 'content' => '<p>Body without image</p>', 'remove_featured_image' => '1']))->status();
        $out['featured cleared'] = $article->fresh()->featured_image_path === null;
        $out['inline deleted when removed from content'] = ! $disk->exists($inlinePath);

        $out['delete'] = $this->delete(route('admin.education.destroy', $article))->status();
        $out['deleted'] = EducationArticle::query()->whereKey($article->id)->doesntExist();
        $out['no files left'] = $disk->allFiles() === [];

        return $out;
    }

    public function test_admin_and_editor_get_identical_results_for_every_action(): void
    {
        $admin = $this->exerciseEveryAction($this->admin());
        $editor = $this->exerciseEveryAction($this->editor());

        $this->assertSame($admin, $editor, 'Admin and Editor must have identical Education access.');
        $this->assertSame([
            'dashboard link' => true,
            'index ' => 200,
            'index draft' => 200,
            'index published' => 200,
            'create page' => 200,
            'inline upload' => 201,
            'store' => 302,
            'saved as draft' => 'draft',
            'column' => 'right',
            'inline kept' => true,
            'preview draft' => 200,
            'public draft' => 404,
            'edit page' => 200,
            'replace featured' => 302,
            'old featured deleted' => true,
            'still draft after save' => 'draft',
            'publish' => 302,
            'public after publish' => 200,
            'unpublish' => 302,
            'public after unpublish' => 404,
            'remove featured' => 302,
            'featured cleared' => true,
            'inline deleted when removed from content' => true,
            'delete' => 302,
            'deleted' => true,
            'no files left' => true,
        ], $editor);
    }

    // --- validation ------------------------------------------------------------------

    public function test_required_fields_and_allowed_values_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.education.store'), ['title' => '', 'short_description' => '', 'content' => '', 'display_column' => 'middle', 'sort_order' => -1])
            ->assertSessionHasErrors(['title', 'short_description', 'content', 'display_column', 'sort_order']);

        $this->actingAs($this->admin())
            ->post(route('admin.education.store'), $this->payload(['title' => str_repeat('a', 256), 'short_description' => str_repeat('a', 501), 'sort_order' => 'first']))
            ->assertSessionHasErrors(['title', 'short_description', 'sort_order']);
    }

    public function test_buttons_need_both_a_label_and_a_safe_link(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.education.store'), $this->payload(['button_label' => 'Go']))->assertSessionHasErrors('button_url');
        $this->actingAs($admin)->post(route('admin.education.store'), $this->payload(['secondary_button_url' => '/contact']))->assertSessionHasErrors('secondary_button_label');

        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.example', 'http://example.com', 'ftp://example.com', 'example.com', 'https://', '/path with space'] as $bad) {
            $this->actingAs($admin)->post(route('admin.education.store'), $this->payload(['button_label' => 'Go', 'button_url' => $bad]))->assertSessionHasErrors('button_url');
        }

        foreach (['/membership/apply', 'https://example.com/page'] as $good) {
            $this->actingAs($admin)->post(route('admin.education.store'), $this->payload(['title' => 'Ok '.$good, 'button_label' => 'Go', 'button_url' => $good]))->assertSessionHasNoErrors();
        }
    }

    public function test_status_slug_and_users_cannot_be_set_from_the_form(): void
    {
        $this->actingAs($this->admin())->post(route('admin.education.store'), $this->payload([
            'status' => 'published', 'published_at' => '2020-01-01', 'slug' => 'chosen', 'created_by_user_id' => 999,
        ]));

        $article = $this->newest();
        $this->assertSame('draft', $article->status);
        $this->assertNull($article->published_at);
        $this->assertSame('air-traffic-control-careers', $article->slug);
        $this->assertNotSame(999, $article->created_by_user_id);
    }

    public function test_duplicate_titles_get_unique_slugs_including_against_the_migrated_cards(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.education.store'), $this->payload(['title' => 'Flight Training']));
        $this->actingAs($admin)->post(route('admin.education.store'), $this->payload(['title' => 'Flight Training']));

        $this->assertSame(['flight-training', 'flight-training-2', 'flight-training-3'], EducationArticle::query()->where('title', 'Flight Training')->orderBy('id')->pluck('slug')->all());
    }

    public function test_saving_never_publishes_or_unpublishes(): void
    {
        $published = EducationArticle::factory()->published()->create();
        $draft = EducationArticle::factory()->create();
        $editor = $this->editor();

        $this->actingAs($editor)->patch(route('admin.education.update', $published), $this->payload(['title' => 'Changed']))->assertSessionHasNoErrors();
        $this->actingAs($editor)->patch(route('admin.education.update', $draft), $this->payload(['title' => 'Changed too']))->assertSessionHasNoErrors();

        $this->assertSame('published', $published->fresh()->status);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame($editor->id, $draft->fresh()->updated_by_user_id);
    }

    // --- content sanitisation and inline images ---------------------------------------

    public function test_content_is_sanitised_and_only_this_modules_images_are_kept(): void
    {
        $own = $this->inlineImage();
        $this->actingAs($this->admin())->post(route('admin.education.store'), $this->payload(['content' => implode('', [
            '<p onclick="x()">Hello <script>alert(1)</script><a href="javascript:alert(1)">link</a></p>',
            '<p><img src="/storage/'.$own.'" alt="Ours" onerror="alert(1)" width="900" class="big"></p>',
            '<p><img src="'.config('app.url').'/storage/'.$own.'" alt="Absolute own"></p>',
            '<p><img src="https://evil.example/storage/'.$own.'" alt="Other host"></p>',
            '<p><img src="data:image/png;base64,AAAA" alt="Data"></p>',
            '<p><img src="/storage/education/featured/abc.png" alt="Other folder"></p>',
            '<p><img src="/storage/'.$own.'?x=1" alt="Query"></p>',
            '<p><img src="/storage/education/inline/../../secret.png" alt="Traversal"></p>',
        ])]))->assertSessionHasNoErrors();

        $content = $this->newest()->content;

        $this->assertStringContainsString('<p>Hello <a>link</a></p>', $content);
        $this->assertStringContainsString('<img src="/storage/'.$own.'" alt="Ours">', $content);
        $this->assertStringContainsString('<img src="/storage/'.$own.'" alt="Absolute own">', $content, 'An absolute URL on this site is stored as a bare path.');
        foreach (['evil.example', 'data:', 'featured/abc', '?x=1', 'secret', 'onerror', 'onclick', 'script', 'width', 'class'] as $gone) {
            $this->assertStringNotContainsString($gone, $content);
        }
    }

    public function test_other_modules_still_strip_every_image(): void
    {
        $own = '/storage/'.$this->inlineImage();

        $this->assertSame('<p>Text</p>', HtmlSanitizer::clean('<p>Text<img src="'.$own.'" alt="x"></p>'));
        $this->assertSame('<p>Text</p>', HtmlSanitizer::forDisplay('<p>Text<img src="'.$own.'" alt="x"></p>'));
    }

    public function test_inline_upload_stores_a_random_name_and_returns_its_url(): void
    {
        $response = $this->actingAs($this->editor())->post(route('admin.education.images.store'), ['image' => $this->pngUpload('My Photo.png')], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertMatchesRegularExpression('#^/storage/education/inline/[0-9a-z]{26}\.png$#', $response->json('url'));
        Storage::disk('public')->assertExists(ltrim(substr($response->json('url'), strlen('/storage/')), '/'));
    }

    public function test_inline_upload_rejects_non_images_disguised_files_and_oversized_images(): void
    {
        $editor = $this->editor();

        foreach ([
            $this->pdfUpload('doc.pdf'),
            $this->realUpload('photo.png', $this->pdfContent(), 'image/png'),
            $this->realUpload('shell.php', '<?php echo 1;', 'image/png'),
            $this->realUpload('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'),
            $this->realUpload('big.png', $this->pngContent().str_repeat("\0", (config('uploads.education_inline_image.max_kb') + 1) * 1024), 'image/png'),
        ] as $file) {
            $this->actingAs($editor)->post(route('admin.education.images.store'), ['image' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_images_still_used_by_another_article_or_a_draft_are_kept(): void
    {
        $shared = $this->inlineImage();
        $draft = EducationArticle::factory()->create(['content' => $this->imageTag($shared)]);
        $published = EducationArticle::factory()->published()->create(['content' => $this->imageTag($shared)]);
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.education.update', $published), $this->payload(['content' => '<p>No image now</p>']))->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists($shared);

        $this->actingAs($admin)->delete(route('admin.education.destroy', $published))->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists($shared);
        $this->assertStringContainsString($shared, $draft->fresh()->content);
    }

    public function test_deleting_an_article_removes_its_featured_and_unshared_inline_images(): void
    {
        $own = $this->inlineImage();
        $shared = $this->inlineImage();
        EducationArticle::factory()->create(['content' => $this->imageTag($shared)]);
        $article = EducationArticle::factory()->published()->create([
            'content' => $this->imageTag($own).$this->imageTag($shared),
            'featured_image_path' => 'education/featured/f.png',
        ]);
        Storage::disk('public')->put('education/featured/f.png', 'png');

        $this->actingAs($this->editor())->delete(route('admin.education.destroy', $article))->assertRedirect(route('admin.education.index'));

        $this->assertModelMissing($article);
        Storage::disk('public')->assertMissing($own);
        Storage::disk('public')->assertMissing('education/featured/f.png');
        Storage::disk('public')->assertExists($shared);
    }

    // --- featured image --------------------------------------------------------------

    public function test_featured_image_upload_replace_remove_and_priority(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.education.store'), $this->payload(['featured_image' => $this->pngUpload(), 'featured_image_alt' => 'Alt']));
        $article = $this->newest();
        $first = $article->featured_image_path;
        $this->assertMatchesRegularExpression('#^education/featured/[0-9a-z]{26}\.png$#', $first);
        $this->assertSame('Alt', $article->featured_image_alt);

        $this->actingAs($admin)->patch(route('admin.education.update', $article), $this->payload());
        $this->assertSame($first, $article->fresh()->featured_image_path, 'No upload and no removal keeps the image.');

        $this->actingAs($admin)->patch(route('admin.education.update', $article), $this->payload(['remove_featured_image' => '1', 'featured_image' => $this->pngUpload('second.png')]));
        $second = $article->fresh()->featured_image_path;
        $this->assertNotSame($first, $second, 'A new upload takes priority over removal.');
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->actingAs($admin)->patch(route('admin.education.update', $article), $this->payload(['remove_featured_image' => '1']))
            ->assertSessionHas('status', 'Article updated and image removed.');
        $this->assertNull($article->fresh()->featured_image_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_invalid_featured_images_are_rejected(): void
    {
        $admin = $this->admin();

        foreach ([$this->pdfUpload('x.pdf'), $this->realUpload('x.png', $this->pdfContent(), 'image/png')] as $file) {
            $this->actingAs($admin)->post(route('admin.education.store'), $this->payload(['featured_image' => $file]))->assertSessionHasErrors('featured_image');
        }

        $this->assertSame(9, EducationArticle::query()->count(), 'Only the nine migrated cards exist.');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // --- failed saves -------------------------------------------------------------------

    private function assertNotSaved(TestResponse $response): void
    {
        $response->assertRedirect()->assertSessionHasErrors('article')->assertSessionMissing('status');
    }

    public function test_a_failed_edit_keeps_everything_including_images(): void
    {
        $inline = $this->inlineImage();
        Storage::disk('public')->put('education/featured/f.png', 'png');
        $article = EducationArticle::factory()->create(['content' => $this->imageTag($inline), 'featured_image_path' => 'education/featured/f.png']);
        $before = $article->fresh()->getAttributes();
        EducationArticle::updating(fn () => false);

        $this->assertNotSaved($this->actingAs($this->editor())->patch(route('admin.education.update', $article), $this->payload(['content' => '<p>No image</p>', 'remove_featured_image' => '1'])));

        $this->assertSame($before, $article->fresh()->getAttributes());
        Storage::disk('public')->assertExists($inline);
        Storage::disk('public')->assertExists('education/featured/f.png');
    }

    public function test_a_failed_image_replacement_rolls_back_and_removes_the_new_file(): void
    {
        Storage::disk('public')->put('education/featured/f.png', 'png');
        $article = EducationArticle::factory()->create(['featured_image_path' => 'education/featured/f.png']);
        EducationArticle::updating(fn (EducationArticle $updating) => ! $updating->isDirty('featured_image_path'));

        $this->assertNotSaved($this->actingAs($this->admin())->patch(route('admin.education.update', $article), $this->payload(['title' => 'Changed', 'featured_image' => $this->pngUpload()])));

        $this->assertSame('education/featured/f.png', $article->fresh()->featured_image_path);
        $this->assertNotSame('Changed', $article->fresh()->title);
        $this->assertSame(['education/featured/f.png'], Storage::disk('public')->allFiles());
    }

    public function test_failed_create_publish_unpublish_and_delete_change_nothing(): void
    {
        $count = EducationArticle::query()->count();
        $draft = EducationArticle::factory()->create();
        $published = EducationArticle::factory()->published()->create();
        $editor = $this->editor();

        EducationArticle::creating(fn () => false);
        EducationArticle::updating(fn () => false);
        EducationArticle::deleting(fn () => false);

        $this->assertNotSaved($this->actingAs($editor)->post(route('admin.education.store'), $this->payload(['featured_image' => $this->pngUpload()])));
        $this->assertNotSaved($this->actingAs($editor)->post(route('admin.education.publish', $draft)));
        $this->assertNotSaved($this->actingAs($editor)->post(route('admin.education.unpublish', $published)));
        $this->assertNotSaved($this->actingAs($editor)->delete(route('admin.education.destroy', $published)));

        $this->assertSame($count + 2, EducationArticle::query()->count());
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('published', $published->fresh()->status);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_featured_image_action_reports_a_silent_save_failure(): void
    {
        $article = EducationArticle::factory()->create();
        EducationArticle::updating(fn () => false);

        $this->expectException(RuntimeException::class);

        try {
            app(UpdateEducationFeaturedImage::class)->handle($article, $this->pngUpload());
        } finally {
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    // --- management interface ------------------------------------------------------------

    public function test_the_index_lists_all_articles_with_filters_and_the_form_offers_the_image_editor(): void
    {
        EducationArticle::factory()->create(['title' => 'Draft one']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.education.index'))->assertSee(['Draft one', 'Flight Training', 'Join the community']);
        $this->actingAs($admin)->get(route('admin.education.index', ['status' => 'draft']))->assertSee('Draft one')->assertDontSee('Flight Training');
        $this->actingAs($admin)->get(route('admin.education.index', ['status' => 'published']))->assertSee('Flight Training')->assertDontSee('Draft one');

        $this->actingAs($admin)->get(route('admin.education.create'))->assertOk()
            ->assertSee('data-rte-image-url="'.route('admin.education.images.store').'"', false)
            ->assertSee('name="display_column" value="left"', false)
            ->assertSee('name="display_column" value="right"', false);
    }

    public function test_preview_shows_a_draft_with_a_banner_only_to_managers(): void
    {
        $draft = EducationArticle::factory()->create(['title' => 'Secret draft']);

        $this->actingAs($this->editor())->get(route('admin.education.show', $draft))->assertOk()->assertSee('Secret draft')->assertSee('this draft is not visible to the public');
        $this->actingAs($this->member())->get(route('admin.education.show', $draft))->assertForbidden();
        $this->get(route('education.show', $draft))->assertNotFound();
    }

    public function test_admin_and_editor_see_the_education_link_and_other_roles_do_not(): void
    {
        $link = 'href="'.route('admin.education.index').'"';

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee($link, false);
        $this->actingAs($this->editor())->get(route('editor.dashboard'))->assertSee($link, false);
        $this->actingAs($this->dev())->get(route('dev.dashboard'))->assertDontSee($link, false);
    }
}
