<?php

namespace Tests\Feature\Eshop;

use App\Actions\Cart\ResolveCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Orders\OrderPlaced;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class CustomerRecoveryTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('private');
        EncryptCookies::except(ResolveCart::COOKIE_NAME);
        $this->eshopBankAccount();
        $this->configureDeliveryCharge();
    }

    protected function tearDown(): void
    {
        EncryptCookies::flushState();

        parent::tearDown();
    }

    private function guestCheckout(): Order
    {
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->stockedProduct(40)->id, 'quantity' => 1]);

        $this->withUnencryptedCookie(ResolveCart::COOKIE_NAME, $cart->guest_token)
            ->post(route('checkout.store'), $this->checkoutDetails())
            ->assertSessionHasNoErrors();

        return Order::query()->sole();
    }

    private function placedEmailFor(Order $order): MailMessage
    {
        $sent = null;
        Notification::assertSentOnDemand(OrderPlaced::class, function (OrderPlaced $notification, array $channels, AnonymousNotifiable $notifiable) use ($order, &$sent): bool {
            $sent = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === $order->customer_email;
        });

        return $sent;
    }

    public function test_a_guest_receives_one_order_confirmation_with_total_deadline_and_signed_link(): void
    {
        $order = $this->guestCheckout();

        Notification::assertSentOnDemandTimes(OrderPlaced::class, 1);
        $mail = $this->placedEmailFor($order);
        $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

        $this->assertStringContainsString('payment required', $mail->subject);
        $this->assertStringContainsString('Delivery (Sri Lanka): LKR 350.00', $text);
        $this->assertStringContainsString('Total to pay: LKR 390.00', $text);
        $this->assertStringContainsString('Please complete payment by', $text);
        $this->assertStringContainsString('signature=', $mail->actionUrl);
        $this->assertStringNotContainsString('[TEST]', $mail->subject);
    }

    public function test_the_emailed_link_lets_a_guest_return_later_and_submit_evidence_without_a_session(): void
    {
        $order = $this->guestCheckout();
        $link = $this->placedEmailFor($order)->actionUrl;

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->get($link)->assertOk()->assertSee($order->order_number)->assertSee('Submit transfer details');
        $this->post(str_replace('/payment/'.$order->public_id, '/payment/'.$order->public_id.'/bank-transfer', $link), [
            'reference' => 'TRX-LATER',
            'evidence' => [$this->pdfUpload()],
        ])->assertForbidden(); // a signature is per route: the page's own form carries the right one

        $form = $this->get($link)->getContent();
        preg_match('#action="([^"]+/bank-transfer[^"]*)"#', $form, $matches);
        $this->post(html_entity_decode($matches[1]), ['reference' => 'TRX-LATER', 'evidence' => [$this->pdfUpload()]])->assertSessionHasNoErrors();

        $this->assertSame(Payment::STATUS_PROCESSING, $order->latestPayment()->status);
    }

    public function test_a_tampered_or_unsigned_link_does_not_open_a_guest_order(): void
    {
        $order = $this->guestCheckout();
        $link = $this->placedEmailFor($order)->actionUrl;

        $this->get($link.'x')->assertForbidden();
        $this->get(route('payment.show', $order))->assertForbidden();
    }

    public function test_a_member_gets_a_plain_link_to_their_own_order(): void
    {
        $member = User::factory()->active()->create();
        $cart = Cart::factory()->create(['user_id' => $member->id]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->stockedProduct()->id, 'quantity' => 1]);

        $this->actingAs($member)->post(route('checkout.store'), $this->checkoutDetails(['email' => $member->email]))->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $mail = $this->placedEmailFor($order);
        $this->assertSame(route('payment.show', $order), $mail->actionUrl);
    }

    public function test_a_failed_checkout_sends_no_confirmation(): void
    {
        $cart = Cart::factory()->guest()->create();

        $this->withUnencryptedCookie(ResolveCart::COOKIE_NAME, $cart->guest_token)->post(route('checkout.store'), $this->checkoutDetails());

        Notification::assertNothingSent();
    }

    public function test_test_orders_are_labelled_in_the_confirmation(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_DUMMY);
        $mail = (new OrderPlaced($order))->toMail(new AnonymousNotifiable);

        $this->assertStringStartsWith('[TEST]', $mail->subject);
        $this->assertStringContainsString('No real payment will be taken', implode(' ', $mail->introLines));
    }
}
