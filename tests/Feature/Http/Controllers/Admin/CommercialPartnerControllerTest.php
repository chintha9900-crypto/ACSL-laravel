<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\CommercialPartner;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class CommercialPartnerControllerTest extends MysqlTestCase
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
        // The form's only three fields.
        return [
            'logo' => $this->pngUpload('skyline-logo.png'),
            'name' => 'Skyline Flight School',
            'url' => 'https://skyline.example.test',
            ...$overrides,
        ];
    }

    // --- authorization ------------------------------------------------------

    public function test_a_guest_cannot_reach_any_admin_commercial_partner_route(): void
    {
        $partner = CommercialPartner::factory()->create();

        $this->get(route('admin.commercial-partners.index'))->assertRedirect(route('login'));
        $this->get(route('admin.commercial-partners.create'))->assertRedirect(route('login'));
        $this->post(route('admin.commercial-partners.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('admin.commercial-partners.show', $partner))->assertRedirect(route('login'));
        $this->get(route('admin.commercial-partners.edit', $partner))->assertRedirect(route('login'));
        $this->patch(route('admin.commercial-partners.update', $partner), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('admin.commercial-partners.destroy', $partner))->assertRedirect(route('login'));
        $this->post(route('admin.commercial-partners.activate', $partner))->assertRedirect(route('login'));
        $this->post(route('admin.commercial-partners.deactivate', $partner))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_reach_any_admin_commercial_partner_route(): void
    {
        $partner = CommercialPartner::factory()->create();
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.commercial-partners.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.commercial-partners.create'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.commercial-partners.store'), $this->payload())->assertForbidden();
        $this->actingAs($member)->get(route('admin.commercial-partners.show', $partner))->assertForbidden();
        $this->actingAs($member)->get(route('admin.commercial-partners.edit', $partner))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.commercial-partners.update', $partner), $this->payload())->assertForbidden();
        $this->actingAs($member)->delete(route('admin.commercial-partners.destroy', $partner))->assertForbidden();
        $this->actingAs($member)->post(route('admin.commercial-partners.activate', $partner))->assertForbidden();
        $this->actingAs($member)->post(route('admin.commercial-partners.deactivate', $partner))->assertForbidden();
    }

    public function test_an_inactive_admin_cannot_reach_any_admin_commercial_partner_route(): void
    {
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'status' => 'suspended']);

        $this->actingAs($inactiveAdmin)->get(route('admin.commercial-partners.index'))->assertRedirect(route('login'));
    }

    public function test_an_admin_can_access_the_commercial_partners_dashboard_and_create_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/commercial-partners')->assertOk();
        $this->actingAs($admin)->get('/admin/commercial-partners/create')->assertOk();
    }

    public function test_an_admin_can_list_commercial_partners(): void
    {
        CommercialPartner::factory()->create(['name' => 'Visible To Admin']);

        $this->actingAs($this->admin())
            ->get(route('admin.commercial-partners.index'))
            ->assertOk()
            ->assertSee('Visible To Admin');
    }

    public function test_an_admin_can_filter_partners_by_active_status(): void
    {
        CommercialPartner::factory()->create(['name' => 'Active One', 'is_active' => true]);
        CommercialPartner::factory()->create(['name' => 'Inactive One', 'is_active' => false]);

        $activeOnly = $this->actingAs($this->admin())->get(route('admin.commercial-partners.index', ['active' => '1']));
        $activeOnly->assertSee('Active One')->assertDontSee('Inactive One');

        $inactiveOnly = $this->actingAs($this->admin())->get(route('admin.commercial-partners.index', ['active' => '0']));
        $inactiveOnly->assertSee('Inactive One')->assertDontSee('Active One');
    }

    public function test_an_admin_can_view_a_single_partner(): void
    {
        $partner = CommercialPartner::factory()->create(['name' => 'Detail Page Partner']);

        $this->actingAs($this->admin())
            ->get(route('admin.commercial-partners.show', $partner))
            ->assertOk()
            ->assertSee('Detail Page Partner');
    }

    // --- create / validation -------------------------------------------------

    public function test_an_admin_can_create_a_partner_as_inactive(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.commercial-partners.store'), $this->payload());

        $response->assertRedirect(route('admin.commercial-partners.index'));
        $partner = CommercialPartner::query()->firstOrFail();
        $this->assertFalse($partner->is_active);
        $this->assertSame('Skyline Flight School', $partner->name);
    }

    public function test_the_form_asks_only_for_logo_name_and_website(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.commercial-partners.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="logo"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="url"', $html);
        $this->assertStringContainsString('data-logo-preview', $html);
        $this->assertStringNotContainsString('name="description"', $html);
        $this->assertStringNotContainsString('name="display_order"', $html);
        $this->assertStringNotContainsString('name="topic"', $html);
    }

    public function test_description_and_display_order_are_no_longer_required_or_overwritten(): void
    {
        $this->actingAs($this->admin())->post(route('admin.commercial-partners.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(0, CommercialPartner::query()->firstOrFail()->display_order);

        $existing = CommercialPartner::factory()->create(['description' => 'Kept history', 'display_order' => 7, 'logo_path' => 'partners/old.png']);
        $this->actingAs($this->admin())->patch(route('admin.commercial-partners.update', $existing), [
            'name' => 'Renamed', 'url' => 'https://renamed.example.test',
        ])->assertSessionHasNoErrors();

        $existing->refresh();
        $this->assertSame('Kept history', $existing->description);
        $this->assertSame(7, $existing->display_order);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.commercial-partners.store'), [])
            ->assertSessionHasErrors(['name', 'url', 'logo']);

        $this->assertSame(0, CommercialPartner::query()->count());
    }

    public function test_the_logo_must_be_a_real_image_within_the_size_limit(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.commercial-partners.store'), $this->payload(['logo' => $this->pdfUpload('logo.png')]))
            ->assertSessionHasErrors('logo');
        $this->actingAs($admin)->post(route('admin.commercial-partners.store'), $this->payload([
            'logo' => UploadedFile::fake()->create('huge.png', config('uploads.commercial_partner_logo.max_kb') + 1, 'image/png'),
        ]))->assertSessionHasErrors('logo');

        $this->assertSame(0, CommercialPartner::query()->count());
    }

    public function test_an_invalid_url_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.commercial-partners.store'), $this->payload(['url' => 'not-a-url']))
            ->assertSessionHasErrors('url');

        $this->assertSame(0, CommercialPartner::query()->count());
    }

    public function test_a_valid_url_is_accepted_and_a_blank_or_unsafe_url_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('admin.commercial-partners.store'), $this->payload(['url' => 'https://example.test/partners']))
            ->assertSessionHasNoErrors();
        $this->assertSame('https://example.test/partners', CommercialPartner::query()->firstOrFail()->url);

        foreach (['', 'javascript:alert(1)', 'ftp://example.test', 'example.test'] as $invalid) {
            $this->actingAs($this->admin())->post(route('admin.commercial-partners.store'), $this->payload(['name' => 'Bad '.$invalid, 'url' => $invalid]))
                ->assertSessionHasErrors('url');
        }
        $this->assertSame(1, CommercialPartner::query()->count());
    }

    public function test_a_logo_can_be_uploaded_on_create(): void
    {
        $this->actingAs($this->admin())->post(route('admin.commercial-partners.store'), [
            ...$this->payload(),
            'logo' => $this->pngUpload(),
        ]);

        $partner = CommercialPartner::query()->firstOrFail();
        $this->assertNotNull($partner->logo_path);
        Storage::disk('public')->assertExists($partner->logo_path);
    }

    // --- update ---------------------------------------------------------------

    public function test_an_admin_can_update_a_partners_fields(): void
    {
        $partner = CommercialPartner::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->admin())->patch(route('admin.commercial-partners.update', $partner), $this->payload([
            'name' => 'New Name',
        ]))->assertRedirect(route('admin.commercial-partners.index'));

        $this->assertSame('New Name', $partner->fresh()->name);
    }

    public function test_editing_keeps_the_existing_logo_unless_a_new_one_is_uploaded(): void
    {
        Storage::disk('public')->put('partners/existing.png', 'png');
        $partner = CommercialPartner::factory()->create(['logo_path' => 'partners/existing.png']);

        $this->actingAs($this->admin())->get(route('admin.commercial-partners.edit', $partner))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url('partners/existing.png'), false)
            ->assertDontSee('name="logo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required', false);

        $this->actingAs($this->admin())->patch(route('admin.commercial-partners.update', $partner), ['name' => 'Same Logo', 'url' => 'https://same.example.test'])
            ->assertSessionHasNoErrors();

        $this->assertSame('partners/existing.png', $partner->fresh()->logo_path);
        Storage::disk('public')->assertExists('partners/existing.png');
    }

    public function test_a_partner_without_a_logo_must_get_one_when_edited(): void
    {
        $partner = CommercialPartner::factory()->create(['logo_path' => null]);

        $this->actingAs($this->admin())->patch(route('admin.commercial-partners.update', $partner), ['name' => 'X', 'url' => 'https://x.example.test'])
            ->assertSessionHasErrors('logo');
    }

    public function test_an_uploaded_logo_gets_a_generated_filename(): void
    {
        $this->actingAs($this->admin())->post(route('admin.commercial-partners.store'), $this->payload(['logo' => $this->pngUpload('../../evil name.png')]));

        $path = CommercialPartner::query()->firstOrFail()->logo_path;
        $this->assertMatchesRegularExpression('#^partners/[0-9a-z]{26}\.png$#', $path);
    }

    public function test_updating_does_not_change_is_active(): void
    {
        $partner = CommercialPartner::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin())->patch(route('admin.commercial-partners.update', $partner), $this->payload());

        $this->assertTrue($partner->fresh()->is_active);
    }

    public function test_the_update_form_has_no_field_that_can_set_is_active(): void
    {
        $partner = CommercialPartner::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin())->patch(route('admin.commercial-partners.update', $partner), [
            ...$this->payload(),
            'is_active' => true,
        ]);

        $this->assertFalse($partner->fresh()->is_active, 'The update endpoint must never read is_active from the request.');
    }

    public function test_replacing_a_logo_deletes_the_previous_file(): void
    {
        $this->actingAs($this->admin())->post(route('admin.commercial-partners.store'), [...$this->payload(), 'logo' => $this->pngUpload()]);
        $partner = CommercialPartner::query()->firstOrFail();
        $firstPath = $partner->logo_path;

        $this->actingAs($this->admin())->patch(route('admin.commercial-partners.update', $partner), [
            ...$this->payload(),
            'logo' => $this->pngUpload('second.png'),
        ]);

        $partner->refresh();
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($partner->logo_path);
    }

    // --- activate / deactivate ----------------------------------------------

    public function test_an_admin_can_activate_a_partner(): void
    {
        $partner = CommercialPartner::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin())->post(route('admin.commercial-partners.activate', $partner))->assertRedirect();

        $this->assertTrue($partner->fresh()->is_active);
    }

    public function test_an_admin_can_deactivate_a_partner(): void
    {
        $partner = CommercialPartner::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin())->post(route('admin.commercial-partners.deactivate', $partner))->assertRedirect();

        $this->assertFalse($partner->fresh()->is_active);
    }

    // --- delete ------------------------------------------------------------------

    public function test_an_admin_can_delete_a_partner_and_its_logo(): void
    {
        $this->actingAs($this->admin())->post(route('admin.commercial-partners.store'), [
            ...$this->payload(),
            'logo' => $this->pngUpload(),
        ]);
        $partner = CommercialPartner::query()->firstOrFail();
        $path = $partner->logo_path;

        $this->actingAs($this->admin())->delete(route('admin.commercial-partners.destroy', $partner))->assertRedirect();

        $this->assertSame(0, CommercialPartner::query()->count());
        Storage::disk('public')->assertMissing($path);
    }
}
