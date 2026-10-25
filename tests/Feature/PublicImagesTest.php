<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\CsrProject;
use App\Models\EventListing;
use App\Models\Inventory;
use App\Models\NewsItem;
use App\Models\Product;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\MysqlTestCase;

/**
 * Public content images: URLs are built from the public disk (APP_URL +
 * /storage + the stored relative path), every content image opts into the
 * load-failure fallback, pages without an image show the placeholder, and
 * nothing under /storage can reach the private documents disk.
 */
class PublicImagesTest extends MysqlTestCase
{
    private function publicUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/storage/'.$path;
    }

    public function test_every_content_type_renders_its_image_from_the_public_disk_with_a_fallback(): void
    {
        $product = Product::factory()->create(['name' => 'Club Cap']);
        $product->images()->create(['image_path' => 'products/cap.png', 'display_order' => 0]);
        Inventory::factory()->create(['product_id' => $product->id, 'quantity' => 3]);
        $post = BlogPost::factory()->published()->create(['featured_image_path' => 'blog/post.jpg', 'featured_image_alt' => 'Runway at dusk']);
        $news = NewsItem::factory()->published()->create(['title' => 'Air show news', 'image_path' => 'news/item.png']);
        $event = EventListing::factory()->published()->create(['title' => 'Fly-in day', 'image_path' => 'events/event.png']);
        $csr = CsrProject::factory()->published()->create(['title' => 'School visit', 'image_path' => 'csr/project.png']);

        $pages = [
            [route('eshop.index'), 'products/cap.png', 'Club Cap'],
            [route('eshop.show', $product), 'products/cap.png', 'Club Cap'],
            [route('blog.index'), 'blog/post.jpg', ''],
            [route('blog.show', $post->slug), 'blog/post.jpg', 'Runway at dusk'],
            [route('news.index'), 'news/item.png', 'Air show news'],
            [route('news.show', $news->slug), 'news/item.png', 'Air show news'],
            [route('events.index'), 'events/event.png', 'Fly-in day'],
            [route('events.show', $event->slug), 'events/event.png', 'Fly-in day'],
            [route('news-events'), 'news/item.png', 'Air show news'],
            [route('csr.index'), 'csr/project.png', 'School visit'],
            [route('csr.show', $csr->slug), 'csr/project.png', 'School visit'],
        ];

        foreach ($pages as [$page, $path, $alt]) {
            $html = $this->get($page)->assertOk()->getContent();
            $url = $this->publicUrl($path);

            $this->assertMatchesRegularExpression('#<img data-fallback(?: [a-z-]+)* src="'.preg_quote($url, '#').'" alt="'.preg_quote($alt, '#').'"#', $html, "Image on {$page}");
            $this->assertStringNotContainsString('/storage/storage/', $html, "No doubled prefix on {$page}");
        }
    }

    public function test_items_without_an_image_show_the_placeholder_instead_of_a_broken_image(): void
    {
        NewsItem::factory()->published()->create(['title' => 'No picture news', 'image_path' => null]);
        CsrProject::factory()->published()->create(['title' => 'No picture project', 'image_path' => null]);

        foreach ([route('news.index'), route('csr.index')] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString('src="'.$this->publicUrl(''), $html);
            $this->assertStringContainsString('M4 4h16v16H4z', $html, 'The neutral placeholder icon is shown.');
        }
    }

    public function test_the_fallback_script_and_style_are_built_into_the_site_assets(): void
    {
        $this->assertStringContainsString("hasAttribute('data-fallback')", file_get_contents(resource_path('js/app.js')));
        $this->assertStringContainsString('.image-fallback', file_get_contents(resource_path('css/app.css')));
    }

    public function test_news_and_event_listing_images_are_half_the_card_width_on_desktop(): void
    {
        NewsItem::factory()->published()->create(['image_path' => 'news/item.png']);
        EventListing::factory()->published()->create(['image_path' => 'events/event.png']);

        foreach ([route('news.index'), route('events.index')] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('aspect-[16/10] w-full overflow-hidden bg-[#4D4D4D] lg:mx-5 lg:mt-5 lg:w-1/2 lg:rounded-md" data-listing-image', false)
                ->assertSee('object-cover', false);
        }
    }

    public function test_private_documents_can_never_be_reached_through_storage_urls(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('payment-evidence/01test/evidence.pdf', '%PDF-1.4 private');

        $this->assertFalse(Route::has('storage.local'), 'No /storage/{path} route serves the private disk.');
        $this->get('/storage/payment-evidence/01test/evidence.pdf')->assertNotFound();
        $this->get('/storage/../private/payment-evidence/01test/evidence.pdf')->assertNotFound();

        // The only storage link maps the public disk, never the private one.
        $this->assertSame([public_path('storage') => storage_path('app/public')], config('filesystems.links'));
        $this->assertNotSame(config('filesystems.disks.public.root'), config('filesystems.disks.private.root'));
        $this->assertStringNotContainsString(storage_path('app/private'), config('filesystems.disks.public.root'));
    }
}
