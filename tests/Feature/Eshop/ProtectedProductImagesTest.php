<?php

namespace Tests\Feature\Eshop;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEligibleMembers;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

/**
 * Members-only product images are stored on the private disk and delivered
 * only through the membership-checked `eshop.images.show` route; public
 * product images stay ordinary public files.
 */
class ProtectedProductImagesTest extends MysqlTestCase
{
    use CreatesEligibleMembers;
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createViaAdmin(string $accessType, int $images = 2, array $overrides = []): Product
    {
        $this->actingAs($this->admin())->post(route('admin.products.store'), [
            'product_category_id' => ProductCategory::factory()->create()->id,
            'name' => 'Wings Pin '.$accessType,
            'slug' => 'wings-pin-'.strtolower($accessType).'-'.uniqid(),
            'sku' => 'PIN-'.strtoupper(uniqid()),
            'description' => 'Protected description',
            'price' => 2500,
            'access_type' => $accessType,
            'quantity' => 5,
            'images' => array_map(fn (int $i) => $this->pngUpload("pin-{$i}.png"), range(1, $images)),
            ...$overrides,
        ])->assertSessionHasNoErrors();
        auth()->logout();

        $product = Product::query()->latest('id')->firstOrFail();
        $product->forceFill(['is_active' => true])->save();
        Inventory::query()->updateOrCreate(['product_id' => $product->id], ['quantity' => 5]);

        return $product->fresh('images');
    }

    private function imageRoute(ProductImage $image): string
    {
        return route('eshop.images.show', $image);
    }

    private function assertNoImage(TestResponse $response): void
    {
        $response->assertNotFound();
        $this->assertStringNotContainsString($this->pngContent(), (string) $response->getContent());
    }

    // --- storage ---------------------------------------------------------------------

    public function test_members_only_images_are_stored_privately_and_public_ones_publicly(): void
    {
        $member = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY);
        $public = $this->createViaAdmin(Product::ACCESS_PUBLIC);

        foreach ($member->images as $image) {
            Storage::disk('private')->assertExists($image->image_path);
            Storage::disk('public')->assertMissing($image->image_path);
            $this->assertMatchesRegularExpression('#^products/[0-9a-z]{26}\.png$#', $image->image_path);
        }
        foreach ($public->images as $image) {
            Storage::disk('public')->assertExists($image->image_path);
            Storage::disk('private')->assertMissing($image->image_path);
        }
    }

    // --- direct access -------------------------------------------------------------------

    public function test_guests_cannot_retrieve_members_only_images_by_direct_url(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY);

        foreach ($product->images as $image) {
            $this->assertNoImage($this->get($this->imageRoute($image)));
            $this->get('/storage/'.$image->image_path)->assertNotFound();
        }
    }

    public function test_signed_in_users_without_an_eligible_membership_are_denied(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY);
        $image = $product->images->first();

        foreach ([User::factory()->active()->create(), $this->expiredMember(), $this->lapsedButNotYetExpiredMember(), $this->dev()] as $user) {
            $this->assertNoImage($this->actingAs($user)->get($this->imageRoute($image)));
        }
    }

    public function test_eligible_members_can_retrieve_the_main_and_gallery_images(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY, 3);
        $member = $this->eligibleMember();

        foreach ($product->images as $image) {
            $response = $this->actingAs($member)->get($this->imageRoute($image))->assertOk();
            $this->assertSame($this->pngContent(), $response->streamedContent());
            $this->assertSame('image/png', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }

        $html = $this->actingAs($member)->get(route('eshop.show', $product))->assertOk()->getContent();
        foreach ($product->images as $image) {
            $this->assertStringContainsString($this->imageRoute($image), $html);
            $this->assertStringNotContainsString('/storage/'.$image->image_path, $html);
        }
        $this->assertStringContainsString('data-gallery-main src="'.$this->imageRoute($product->images->first()).'"', $html);

        $listing = $this->actingAs($member)->get(route('eshop.index'))->getContent();
        $this->assertStringContainsString($this->imageRoute($product->images->first()), $listing);
    }

    public function test_protected_urls_and_paths_never_appear_in_html_for_unauthorised_visitors(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY, 2);

        foreach ([null, User::factory()->active()->create()] as $viewer) {
            $viewer ? $this->actingAs($viewer) : auth()->logout();

            foreach ([route('eshop.index'), route('eshop.index', ['access' => 'members']), route('eshop.show', $product)] as $page) {
                $html = (string) $this->get($page)->getContent();

                foreach ($product->images as $image) {
                    $this->assertStringNotContainsString($image->image_path, $html, $page);
                    $this->assertStringNotContainsString($this->imageRoute($image), $html, $page);
                }
            }
        }
    }

    public function test_an_inactive_members_only_products_images_are_hidden_even_from_eligible_members(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY);
        $product->forceFill(['is_active' => false])->save();

        $this->assertNoImage($this->actingAs($this->eligibleMember())->get($this->imageRoute($product->images->first())));
    }

    public function test_product_managers_can_preview_protected_images_in_admin(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY);
        $product->forceFill(['is_active' => false])->save();
        $image = $product->images->first();

        foreach ([$this->admin(), $this->editor()] as $staff) {
            $this->actingAs($staff)->get($this->imageRoute($image))->assertOk();
            $this->actingAs($staff)->get(route('admin.products.edit', $product))->assertSee($this->imageRoute($image), false);
        }
    }

    public function test_public_product_images_remain_public_for_everyone(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_PUBLIC);
        $image = $product->images->first();
        $publicUrl = Storage::disk('public')->url($image->image_path);

        $this->get(route('eshop.show', $product))->assertOk()->assertSee($publicUrl, false);
        $this->get($this->imageRoute($image))->assertRedirect($publicUrl);
        $this->actingAs(User::factory()->active()->create())->get(route('eshop.index'))->assertSee($publicUrl, false);
    }

    public function test_missing_protected_files_return_404_for_the_page_fallback(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY);
        $image = $product->images->first();
        Storage::disk('private')->delete($image->image_path);

        $member = $this->eligibleMember();
        $this->actingAs($member)->get($this->imageRoute($image))->assertNotFound();

        // The page still renders; the browser-side fallback replaces the broken image.
        $this->actingAs($member)->get(route('eshop.show', $product))
            ->assertOk()
            ->assertSee('data-fallback data-gallery-main src="'.$this->imageRoute($image).'"', false);
    }

    public function test_private_payment_evidence_can_never_be_served_through_the_image_route(): void
    {
        Storage::disk('private')->put('payment-evidence/01x/evidence.pdf', '%PDF-1.4 secret');
        $product = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY, 1);
        // Even a tampered row pointing at evidence is refused.
        $image = $product->images->first();
        $image->forceFill(['image_path' => 'payment-evidence/01x/evidence.pdf'])->save();

        $response = $this->actingAs($this->admin())->get($this->imageRoute($image));
        $response->assertNotFound();
        $this->assertStringNotContainsString('%PDF-1.4 secret', (string) $response->getContent());
        $this->get('/storage/payment-evidence/01x/evidence.pdf')->assertNotFound();
    }

    // --- access type changes and existing images ----------------------------------------------

    public function test_switching_access_type_moves_files_to_the_matching_disk(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_PUBLIC, 2);
        $paths = $product->images->pluck('image_path')->all();
        $payload = fn (string $access) => [
            'product_category_id' => $product->product_category_id, 'name' => $product->name, 'slug' => $product->slug,
            'sku' => $product->sku, 'price' => 2500, 'access_type' => $access, 'quantity' => 5,
        ];

        $this->actingAs($this->admin())->patch(route('admin.products.update', $product), $payload(Product::ACCESS_MEMBER_ONLY))->assertSessionHasNoErrors();
        foreach ($paths as $path) {
            Storage::disk('private')->assertExists($path);
            Storage::disk('public')->assertMissing($path);
        }
        $this->assertSame($paths, $product->images()->pluck('image_path')->all(), 'Order and rows are unchanged.');

        $this->actingAs($this->admin())->patch(route('admin.products.update', $product), $payload(Product::ACCESS_PUBLIC))->assertSessionHasNoErrors();
        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
            Storage::disk('private')->assertMissing($path);
        }
    }

    public function test_a_file_shared_with_a_public_product_is_copied_not_moved(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_PUBLIC, 1);
        $path = $product->images->first()->image_path;
        $other = Product::factory()->create();
        $other->images()->create(['image_path' => $path, 'display_order' => 0]);

        $this->actingAs($this->admin())->patch(route('admin.products.update', $product), [
            'product_category_id' => $product->product_category_id, 'name' => $product->name, 'slug' => $product->slug,
            'sku' => $product->sku, 'price' => 2500, 'access_type' => Product::ACCESS_MEMBER_ONLY, 'quantity' => 5,
        ])->assertSessionHasNoErrors();

        Storage::disk('private')->assertExists($path);
        // Still needed publicly by the other product, so the public copy stays.
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_command_moves_existing_members_only_images_off_the_public_disk_once(): void
    {
        $member = Product::factory()->memberOnly()->create();
        $public = Product::factory()->create();
        Storage::disk('public')->put('products/legacymember01.png', $this->pngContent());
        Storage::disk('public')->put('products/legacypublic01.png', $this->pngContent());
        $member->images()->create(['image_path' => 'products/legacymember01.png', 'display_order' => 0]);
        $member->images()->create(['image_path' => 'products/missingfile01.png', 'display_order' => 1]);
        $public->images()->create(['image_path' => 'products/legacypublic01.png', 'display_order' => 0]);

        $this->artisan('products:secure-images')->expectsOutputToContain('Moved 1 product image file(s)')->assertSuccessful();
        $this->artisan('products:secure-images')->expectsOutputToContain('Moved 0 product image file(s)')->assertSuccessful();

        Storage::disk('private')->assertExists('products/legacymember01.png');
        Storage::disk('public')->assertMissing('products/legacymember01.png');
        Storage::disk('public')->assertExists('products/legacypublic01.png');
        $this->assertSame(3, ProductImage::query()->count(), 'No image rows are removed.');
    }

    // --- gallery management keeps working for members-only products --------------------------

    public function test_upload_order_primary_removal_and_limit_work_on_private_storage(): void
    {
        $product = $this->createViaAdmin(Product::ACCESS_MEMBER_ONLY, 3);
        [$a, $b, $c] = $product->images->all();
        $base = [
            'product_category_id' => $product->product_category_id, 'name' => $product->name, 'slug' => $product->slug,
            'sku' => $product->sku, 'price' => 2500, 'access_type' => Product::ACCESS_MEMBER_ONLY, 'quantity' => 5,
        ];
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.products.update', $product), [...$base, 'images' => [$this->pngUpload('d.png')], 'primary_image' => $c->id])->assertSessionHasNoErrors();
        $this->assertSame(4, $product->images()->count());
        $this->assertSame($c->id, $product->images()->first()->id);
        Storage::disk('private')->assertExists($product->images()->get()->last()->image_path);

        $this->actingAs($admin)->patch(route('admin.products.update', $product), [...$base, 'images' => [$this->pngUpload('e.png')]])->assertSessionHasErrors('images');
        $this->assertSame(4, $product->images()->count());

        $this->actingAs($admin)->patch(route('admin.products.update', $product), [...$base, 'remove_images' => [$b->id], 'images' => [$this->pngUpload('f.png')]])->assertSessionHasNoErrors();
        Storage::disk('private')->assertMissing($b->image_path);
        Storage::disk('private')->assertExists($a->image_path);
        $this->assertSame(4, $product->images()->count());

        $this->actingAs($admin)->delete(route('admin.products.destroy', $product));
        foreach ([$a, $c] as $image) {
            Storage::disk('private')->assertMissing($image->image_path);
        }
    }
}
