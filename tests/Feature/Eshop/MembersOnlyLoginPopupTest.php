<?php

namespace Tests\Feature\Eshop;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesEligibleMembers;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * The e-shop "Access" options and the guest login popup shown when a guest
 * chooses "Members only products". The popup posts to the existing login
 * route; eligibility after login is still decided by the membership rules.
 */
class MembersOnlyLoginPopupTest extends MysqlTestCase
{
    use CreatesEligibleMembers;
    use CreatesRenewals;
    use CreatesReviewableApplications;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        $public = Product::factory()->create(['name' => 'Public Cap']);
        $secret = Product::factory()->memberOnly()->create(['name' => 'Secret Wings Pin', 'description' => 'Hidden pin text', 'price' => 54321]);
        foreach ([$public, $secret] as $product) {
            Inventory::factory()->create(['product_id' => $product->id, 'quantity' => 5]);
        }
    }

    private function accessBlockText(string $html): string
    {
        $this->assertSame(1, preg_match('#>Access</h2>(.*?)</div>#s', $html, $match));

        return trim(preg_replace('/\s+/', ' ', strip_tags($match[1])));
    }

    private function dialogHtml(string $html): string
    {
        $this->assertSame(1, preg_match('#<dialog id="members-only-dialog".*?</dialog>#s', $html, $match));

        return $match[0];
    }

    private function withPassword(User $user): User
    {
        $user->forceFill(['password' => self::PASSWORD])->save();

        return $user;
    }

    // --- labels -------------------------------------------------------------------------

    public function test_the_access_options_are_products_and_members_only_products_with_no_helper_text(): void
    {
        foreach ([null, User::factory()->active()->create(), $this->eligibleMember()] as $viewer) {
            $viewer ? $this->actingAs($viewer) : auth()->logout();
            $html = $this->get(route('eshop.index'))->assertOk()->getContent();

            // (The separate Categories list keeps its own "All products" link.)
            $this->assertSame('Products Members only products', $this->accessBlockText($html));
            $this->assertStringNotContainsString('data-membership-required', $html);
        }
    }

    // --- popup ---------------------------------------------------------------------------

    public function test_the_popup_is_only_shown_when_a_guest_chooses_members_only_products(): void
    {
        $this->get(route('eshop.index'))->assertOk()->assertDontSee('data-members-only-dialog', false);

        $this->get(route('eshop.index', ['access' => 'members']))->assertOk()->assertSee('data-members-only-dialog', false);
    }

    public function test_the_popup_contains_exactly_the_login_controls_in_order(): void
    {
        $dialog = $this->dialogHtml($this->get(route('eshop.index', ['access' => 'members']))->getContent());

        // Visible text only (the red * marks are the shared form component's required markers).
        $this->assertSame(
            'Please log in to your account Email * Password * Forgot password? Login Cancel',
            trim(preg_replace('/\s+/', ' ', strip_tags($dialog))),
        );

        $this->assertMatchesRegularExpression('#Please log in to your account.*name="email".*name="password".*Forgot password\?.*>Login</button>.*>Cancel</button>#s', $dialog);
        $this->assertStringContainsString('action="'.route('login').'"', $dialog);
        $this->assertStringContainsString('type="email"', $dialog);
        $this->assertStringContainsString('type="password"', $dialog);
        $this->assertStringContainsString('name="_token"', $dialog);
        $this->assertStringContainsString('href="'.route('password.request').'"', $dialog);
        $this->assertStringNotContainsString('Secret Wings Pin', $dialog);
    }

    public function test_cancel_closes_the_popup_in_place_without_submitting_the_login(): void
    {
        $dialog = $this->dialogHtml($this->get(route('eshop.index', ['access' => 'members']))->getContent());

        // Cancel submits the separate method="dialog" form: it only closes the dialog.
        $this->assertStringContainsString('form="members-only-cancel"', $dialog);
        $this->assertStringContainsString('<form method="dialog" id="members-only-cancel"></form>', $dialog);
        $this->assertMatchesRegularExpression('#<button type="submit" form="members-only-cancel"[^>]*>Cancel</button>#', $dialog);
    }

    public function test_the_forgot_password_link_opens_the_existing_reset_flow(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    // --- login through the popup ----------------------------------------------------------

    public function test_an_eligible_member_logs_in_from_the_popup_and_returns_to_members_only_products(): void
    {
        $member = $this->withPassword($this->eligibleMember());
        $page = route('eshop.index', ['access' => 'members']);

        $this->get($page)->assertSee('data-members-only-dialog', false);
        $this->from($page)->post(route('login'), ['email' => $member->email, 'password' => self::PASSWORD])
            ->assertRedirect($page);

        $this->assertAuthenticatedAs($member);
        $this->get($page)
            ->assertOk()
            ->assertSee('Secret Wings Pin')
            ->assertDontSee('Public Cap')
            ->assertDontSee('data-members-only-dialog', false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ineligibleAccounts(): array
    {
        return [
            'active account without a membership' => ['plain'],
            'admin without a membership' => ['admin'],
            'editor without a membership' => ['editor'],
            'expired membership' => ['expired'],
        ];
    }

    #[DataProvider('ineligibleAccounts')]
    public function test_logging_in_alone_does_not_unlock_members_only_products(string $kind): void
    {
        $user = $this->withPassword(match ($kind) {
            'plain' => User::factory()->active()->create(),
            'admin' => $this->admin(),
            'editor' => $this->editor(),
            'expired' => $this->expiredMember(),
        });
        $page = route('eshop.index', ['access' => 'members']);

        $this->get($page);
        $this->from($page)->post(route('login'), ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect($page);

        $html = $this->get($page)->assertOk()->getContent();
        $this->assertStringNotContainsString('Secret Wings Pin', $html);
        $this->assertStringNotContainsString('Hidden pin text', $html);
        $this->assertStringNotContainsString('54,321', $html);

        // Already signed in: the existing access-denied message, never a login form.
        $dialog = $this->dialogHtml($html);
        $this->assertStringNotContainsString('name="password"', $dialog);
        $this->assertStringNotContainsString('Please log in to your account', $dialog);
        $this->assertStringContainsString('only with an eligible Aviation Club membership', $dialog);
    }

    public function test_a_failed_login_returns_to_the_shop_with_the_popup_and_the_error(): void
    {
        $member = $this->withPassword($this->eligibleMember());
        $page = route('eshop.index', ['access' => 'members']);

        $this->get($page);
        $this->from($page)->post(route('login'), ['email' => $member->email, 'password' => 'wrong-password'])
            ->assertRedirect($page)
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $dialog = $this->dialogHtml($this->get($page)->getContent());
        $this->assertStringContainsString('These credentials do not match our records.', $dialog);
        $this->assertStringContainsString('value="'.$member->email.'"', $dialog);
    }

    public function test_the_popup_is_also_used_on_a_members_only_product_page(): void
    {
        $secret = Product::query()->where('name', 'Secret Wings Pin')->sole();

        $html = $this->get(route('eshop.show', $secret))->assertForbidden()->getContent();

        $this->assertStringContainsString('Please log in to your account', $this->dialogHtml($html));
        $this->assertStringNotContainsString('Secret Wings Pin', $html);
    }
}
