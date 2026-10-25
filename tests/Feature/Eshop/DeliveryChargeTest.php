<?php

namespace Tests\Feature\Eshop;

use App\Actions\Cart\ResolveCart;
use App\Exceptions\CheckoutFailedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\EshopSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PayHere;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class DeliveryChargeTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        EncryptCookies::except(ResolveCart::COOKIE_NAME);
        $this->eshopBankAccount();
    }

    protected function tearDown(): void
    {
        EncryptCookies::flushState();

        parent::tearDown();
    }

    private function guestWithCart(int $price = 40, int $quantity = 1): static
    {
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->stockedProduct($price)->id, 'quantity' => $quantity]);

        return $this->withUnencryptedCookie(ResolveCart::COOKIE_NAME, $cart->guest_token);
    }

    // --- unset charge blocks ordering -------------------------------------------------------

    public function test_there_is_no_default_delivery_charge(): void
    {
        $this->assertNull(EshopSetting::deliveryCharge());
        $this->assertFalse(EshopSetting::current()->delivery_charge_configured);
        $this->assertNull(EshopSetting::current()->delivery_charge_amount);
    }

    public function test_checkout_is_blocked_until_the_delivery_charge_is_configured(): void
    {
        $this->guestWithCart()->get(route('checkout.show'))
            ->assertOk()
            ->assertSee('the delivery charge has not been set')
            ->assertDontSee('value="bank_transfer"', false);

        $this->post(route('checkout.store'), $this->checkoutDetails())->assertSessionHasErrors('cart');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_place_order_itself_refuses_an_unconfigured_charge(): void
    {
        $this->expectException(CheckoutFailedException::class);

        $this->placeEshopOrder($this->stockedProduct());
    }

    public function test_the_database_rejects_a_configured_flag_without_an_amount(): void
    {
        EshopSetting::current();

        $this->expectException(QueryException::class);

        DB::table('eshop_settings')->where('id', 1)->update(['delivery_charge_configured' => true, 'delivery_charge_amount' => null]);
    }

    // --- configured charge is included -----------------------------------------------------

    public function test_the_configured_charge_is_shown_and_included_in_the_exact_total(): void
    {
        $this->configureDeliveryCharge('350.50');

        $this->guestWithCart(40, 3)->get(route('checkout.show'))
            ->assertSee('Delivery (Sri Lanka)')
            ->assertSee('LKR 350.50')
            ->assertSee('LKR 470.50');

        $this->post(route('checkout.store'), $this->checkoutDetails())->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame('120.00', $order->subtotal_amount);
        $this->assertSame('350.50', $order->delivery_charge_amount);
        $this->assertSame('470.50', $order->total_amount);
        $this->assertSame('470.50', Payment::query()->sole()->amount, 'The bank-transfer amount includes delivery.');
    }

    public function test_the_payhere_amount_and_hash_include_the_delivery_charge(): void
    {
        $this->configurePayHere();
        $this->configureDeliveryCharge('350.00');
        $order = $this->placeEshopOrder($this->stockedProduct(1500), null, 1, Order::METHOD_PAYHERE);
        $payment = Payment::query()->where('order_id', $order->id)->sole();

        $fields = app(PayHere::class)->checkoutFields($order->load('items'), $payment, 'r', 'c', 'n');

        $this->assertSame('1850.00', $fields['amount']);
        $this->assertSame(strtoupper(md5(self::PAYHERE_TEST_MERCHANT_ID.$order->order_number.'1850.00LKR'.strtoupper(md5(self::PAYHERE_TEST_SECRET)))), $fields['hash']);
    }

    public function test_an_explicit_zero_charge_is_allowed_only_when_configured(): void
    {
        $this->configureDeliveryCharge('0.00');

        $order = $this->placeEshopOrder($this->stockedProduct(40));

        $this->assertSame('0.00', $order->delivery_charge_amount);
        $this->assertSame('40.00', $order->total_amount);
    }

    public function test_a_later_change_never_alters_existing_orders(): void
    {
        $this->configureDeliveryCharge('350.00');
        $order = $this->placeEshopOrder($this->stockedProduct(40));

        $this->actingAs($this->admin())->put(route('admin.eshop-settings.update'), ['delivery_charge' => '500', 'confirm' => '1'])->assertRedirect();

        $order->refresh();
        $this->assertSame('350.00', $order->delivery_charge_amount);
        $this->assertSame('390.00', $order->total_amount);
        $this->assertSame('390.00', Payment::query()->where('order_id', $order->id)->sole()->amount);
        $this->assertSame('500.00', EshopSetting::deliveryCharge());
    }

    public function test_delivery_is_limited_to_sri_lanka_even_inside_place_order(): void
    {
        $this->configureDeliveryCharge();

        $this->expectException(CheckoutFailedException::class);

        $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_BANK_TRANSFER, ['country' => 'IN']);
    }

    // --- admin setting -----------------------------------------------------------------------

    public function test_an_admin_or_commerce_editor_can_set_the_charge_deliberately_and_it_is_audited(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('admin.eshop-settings.edit'))->assertOk()->assertSee('Not set');
        $this->actingAs($editor)->put(route('admin.eshop-settings.update'), ['delivery_charge' => '0', 'confirm' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('0.00', EshopSetting::deliveryCharge());
        $this->assertDatabaseHas('audit_logs', ['event' => 'eshop.delivery_charge_updated', 'subject_type' => 'eshop_setting', 'user_id' => $editor->id]);
        $this->actingAs($editor)->get(route('admin.eshop-settings.edit'))->assertSee('not set → LKR 0.00');
    }

    public function test_saving_requires_explicit_confirmation_and_a_valid_amount(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.eshop-settings.update'), ['delivery_charge' => '350'])->assertSessionHasErrors('confirm');
        foreach (['', '-1', '1e3', '35,0', '350.001', 'abc'] as $invalid) {
            $this->actingAs($admin)->put(route('admin.eshop-settings.update'), ['delivery_charge' => $invalid, 'confirm' => '1'])->assertSessionHasErrors('delivery_charge');
        }

        $this->assertNull(EshopSetting::deliveryCharge());
    }

    public function test_users_without_commerce_permission_cannot_change_it(): void
    {
        foreach ([$this->member(), $this->dev(), User::factory()->active()->create()] as $user) {
            $this->actingAs($user)->put(route('admin.eshop-settings.update'), ['delivery_charge' => '1', 'confirm' => '1'])->assertForbidden();
        }

        auth()->logout();
        $this->put(route('admin.eshop-settings.update'), ['delivery_charge' => '1', 'confirm' => '1'])->assertRedirect(route('login'));
        $this->assertNull(EshopSetting::deliveryCharge());
    }
}
