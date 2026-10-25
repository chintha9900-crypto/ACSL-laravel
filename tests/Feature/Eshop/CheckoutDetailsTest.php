<?php

namespace Tests\Feature\Eshop;

use App\Actions\Cart\ResolveCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBankAccount;
use App\Models\Product;
use App\Models\User;
use App\Payments\PayHere;
use Illuminate\Cookie\Middleware\EncryptCookies;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class CheckoutDetailsTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        EncryptCookies::except(ResolveCart::COOKIE_NAME);
        $this->eshopBankAccount();
        $this->configureDeliveryCharge();
    }

    protected function tearDown(): void
    {
        EncryptCookies::flushState();

        parent::tearDown();
    }

    private function guestWithCartOf(Product $product, int $quantity = 1): static
    {
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $this->withUnencryptedCookie(ResolveCart::COOKIE_NAME, $cart->guest_token);
    }

    public function test_the_checkout_page_shows_the_delivery_fields_and_available_methods(): void
    {
        $this->guestWithCartOf($this->stockedProduct())
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertSee('Delivery address')
            ->assertSee('name="address_line1"', false)
            ->assertSee('name="postcode"', false)
            ->assertSee('value="bank_transfer"', false)
            ->assertDontSee('value="payhere"', false)
            ->assertDontSee('value="dummy"', false);
    }

    public function test_the_delivery_address_and_customer_details_are_snapshotted_on_the_order(): void
    {
        $this->guestWithCartOf($this->stockedProduct())
            ->post(route('checkout.store'), $this->checkoutDetails(['postcode' => 'ab12 3cd', 'phone' => '+94 (77) 123-4567']))
            ->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame('Nimal Perera', $order->customer_name);
        $this->assertSame('+94771234567', $order->customer_phone);
        $this->assertSame('12 Galle Road', $order->delivery_address_line1);
        $this->assertSame('Apartment 3', $order->delivery_address_line2);
        $this->assertSame('Colombo', $order->delivery_city);
        $this->assertSame('AB12 3CD', $order->delivery_postcode);
        $this->assertSame('LK', $order->delivery_country);
        $this->assertSame(Order::METHOD_BANK_TRANSFER, $order->payment_method);
        $this->assertFalse($order->is_test);
    }

    public function test_a_users_own_name_is_not_changed_by_checkout(): void
    {
        $member = User::factory()->active()->create(['name' => 'Kamal']);
        $product = $this->stockedProduct();
        $cart = Cart::factory()->create(['user_id' => $member->id]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($member)->post(route('checkout.store'), $this->checkoutDetails(['name' => 'Kamal Silva']))->assertRedirect();

        $this->assertSame('Kamal', $member->fresh()->name);
        $this->assertSame('Kamal Silva', Order::query()->sole()->customer_name);
    }

    public function test_required_delivery_fields_are_validated(): void
    {
        $this->guestWithCartOf($this->stockedProduct())
            ->post(route('checkout.store'), $this->checkoutDetails(['address_line1' => '', 'city' => '', 'country' => '']))
            ->assertSessionHasErrors(['address_line1', 'city', 'country']);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_unsupported_country_and_invalid_postcode_are_rejected(): void
    {
        $this->guestWithCartOf($this->stockedProduct())
            ->post(route('checkout.store'), $this->checkoutDetails(['country' => 'US', 'postcode' => '<script>']))
            ->assertSessionHasErrors(['country', 'postcode']);
    }

    public function test_postcode_and_address_line_2_are_optional(): void
    {
        $this->guestWithCartOf($this->stockedProduct())
            ->post(route('checkout.store'), $this->checkoutDetails(['postcode' => '', 'address_line2' => '']))
            ->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertNull($order->delivery_postcode);
        $this->assertNull($order->delivery_address_line2);
    }

    public function test_the_phone_is_optional_for_bank_transfer(): void
    {
        $this->guestWithCartOf($this->stockedProduct())
            ->post(route('checkout.store'), $this->checkoutDetails(['phone' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Order::query()->sole()->customer_phone);
    }

    public function test_an_invalid_phone_is_rejected_even_when_optional(): void
    {
        $this->guestWithCartOf($this->stockedProduct())
            ->post(route('checkout.store'), $this->checkoutDetails(['phone' => '0771234567']))
            ->assertSessionHasErrors('phone');
    }

    public function test_the_phone_is_required_and_must_be_valid_for_payhere(): void
    {
        $this->configurePayHere();
        $product = $this->stockedProduct();

        $this->guestWithCartOf($product)
            ->post(route('checkout.store'), $this->checkoutDetails(['payment_method' => 'payhere', 'phone' => '']))
            ->assertSessionHasErrors(['phone' => 'A phone number is required to pay with PayHere.']);

        $this->guestWithCartOf($product)
            ->post(route('checkout.store'), $this->checkoutDetails(['payment_method' => 'payhere', 'phone' => '12345']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_payhere_is_only_accepted_when_configured(): void
    {
        $this->guestWithCartOf($this->stockedProduct())
            ->post(route('checkout.store'), $this->checkoutDetails(['payment_method' => 'payhere']))
            ->assertSessionHasErrors('payment_method');
    }

    public function test_a_payhere_order_creates_a_pending_payhere_attempt(): void
    {
        $this->configurePayHere();

        $this->guestWithCartOf($this->stockedProduct(30))
            ->post(route('checkout.store'), $this->checkoutDetails(['payment_method' => 'payhere']))
            ->assertSessionHasNoErrors();

        $payment = Payment::query()->sole();
        $this->assertSame(Payment::GATEWAY_PAYHERE, $payment->gateway);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame('380.00', $payment->amount, 'Items 30.00 + configured delivery 350.00.');
        $this->assertNull($payment->bank_account_id);
    }

    public function test_bank_transfer_is_not_offered_without_configured_bank_details(): void
    {
        PaymentBankAccount::query()->update(['is_active' => false]);

        $this->guestWithCartOf($this->stockedProduct())
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertSee('no payment method is configured');

        $this->guestWithCartOf($this->stockedProduct())
            ->post(route('checkout.store'), $this->checkoutDetails())
            ->assertSessionHasErrors('payment_method');
    }

    public function test_the_dummy_method_cannot_be_submitted_when_disabled(): void
    {
        $tester = User::factory()->active()->create(['email' => self::DUMMY_TEST_EMAIL]);
        $cart = Cart::factory()->create(['user_id' => $tester->id]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->stockedProduct()->id, 'quantity' => 1]);

        $this->actingAs($tester)
            ->post(route('checkout.store'), $this->checkoutDetails(['payment_method' => 'dummy']))
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_cart_is_cleared_once_the_order_is_placed(): void
    {
        $product = $this->stockedProduct();
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2]);

        $this->withUnencryptedCookie(ResolveCart::COOKIE_NAME, $cart->guest_token)
            ->post(route('checkout.store'), $this->checkoutDetails())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $cart->items()->count());
    }

    public function test_a_failed_validation_keeps_the_cart(): void
    {
        $product = $this->stockedProduct();
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2]);

        $this->withUnencryptedCookie(ResolveCart::COOKIE_NAME, $cart->guest_token)
            ->post(route('checkout.store'), $this->checkoutDetails(['city' => '']))
            ->assertSessionHasErrors('city');

        $this->assertSame(1, $cart->items()->count());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function names(): array
    {
        return [
            'two words' => ['Nimal Perera', 'Nimal', 'Perera'],
            'three words' => ['Anura Kumara Dissanayake', 'Anura', 'Kumara Dissanayake'],
            'single name' => ['Madonna', 'Madonna', 'Madonna'],
            'extra spaces' => ['  Nimal    Perera  ', 'Nimal', 'Perera'],
            'unicode' => ['නිමල් පෙරේරා', 'නිමල්', 'පෙරේරා'],
        ];
    }

    #[DataProvider('names')]
    public function test_payhere_first_and_last_names_are_mapped_from_the_single_name(string $name, string $first, string $last): void
    {
        $this->assertSame([$first, $last], PayHere::splitName($name));
    }
}
