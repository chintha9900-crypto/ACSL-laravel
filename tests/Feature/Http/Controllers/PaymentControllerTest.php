<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\Payments\ConfirmOrderPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Notifications\Orders\OrderReceipt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * The customer payment page, receipts and order access. The old
 * development simulator (confirm/fail/cancel routes) has been replaced by
 * bank transfer, PayHere and the gated dummy adapter, which have their own
 * tests in tests/Feature/Eshop.
 */
class PaymentControllerTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->eshopBankAccount();
        $this->configureDeliveryCharge();
    }

    private function paymentFor(Order $order): Payment
    {
        return Payment::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
    }

    // --- payment page access ------------------------------------------------------

    public function test_the_payment_page_shows_the_amount_status_and_bank_details_for_the_owner(): void
    {
        $member = $this->member();
        $order = $this->placeEshopOrder($this->stockedProduct(40), $member);

        $this->actingAs($member)->get(route('payment.show', $order))
            ->assertOk()
            ->assertSee('LKR 40.00')
            ->assertSee('Awaiting payment')
            ->assertSee($order->order_number)
            ->assertSee('Test Bank')
            ->assertSee('9876543210')
            ->assertSee('Submit transfer details');
    }

    public function test_a_guest_can_reach_their_own_payment_page_via_the_signed_link(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());

        $this->get(URL::signedRoute('payment.show', $order))->assertOk()->assertSee($order->order_number);
    }

    public function test_a_guest_cannot_reach_a_payment_page_without_a_valid_signature(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());

        $this->get(route('payment.show', $order))->assertForbidden();
        $this->get(URL::signedRoute('payment.show', $order).'x')->assertForbidden();
    }

    public function test_a_member_cannot_reach_another_members_payment_page(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), $this->member());

        $this->actingAs($this->member())->get(route('payment.show', $order))->assertForbidden();
    }

    public function test_the_old_simulator_routes_no_longer_exist(): void
    {
        $this->assertFalse(app('router')->has('payment.confirm'));
        $this->assertFalse(app('router')->has('payment.fail'));
        $this->assertFalse(app('router')->has('payment.cancel'));
    }

    public function test_no_visitor_request_can_mark_an_order_paid(): void
    {
        $member = $this->member();
        $order = $this->placeEshopOrder($this->stockedProduct(), $member);

        $this->actingAs($member)->post(route('payment.retry', $order), ['payment_method' => 'bank_transfer', 'status' => 'paid', 'amount' => '1.00']);
        $this->actingAs($member)->get(route('payment.payhere.return', $order));

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PENDING, $this->paymentFor($order)->status);
        Notification::assertNothingSent();
    }

    // --- receipts -------------------------------------------------------------------

    public function test_the_receipt_is_unavailable_until_payment_is_verified(): void
    {
        $member = $this->member();
        $order = $this->placeEshopOrder($this->stockedProduct(), $member);

        $this->actingAs($member)->get(route('orders.receipt', $order))->assertRedirect()->assertSessionHas('status');
    }

    public function test_the_receipt_contains_the_historical_snapshots_and_delivery_address(): void
    {
        $product = Product::factory()->create(['name' => 'ACI Polo Shirt', 'sku' => 'POLO-001', 'price' => 25]);
        $product->inventory()->create(['quantity' => 5]);
        $member = $this->member();
        $order = $this->placeEshopOrder($product, $member, 2);
        app(ConfirmOrderPayment::class)->handle($this->paymentFor($order));

        $product->update(['name' => 'Renamed Product', 'sku' => 'POLO-999', 'price' => 999]);

        $this->actingAs($member)->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('ACI Polo Shirt')
            ->assertSee('POLO-001')
            ->assertSee('LKR 50.00')
            ->assertSee('12 Galle Road')
            ->assertSee('Colombo 00300')
            ->assertSee('Bank transfer')
            ->assertDontSee('Renamed Product')
            ->assertDontSee('TEST RECEIPT');
    }

    public function test_the_receipt_stays_available_after_fulfilment_moves_the_order_on(): void
    {
        $member = $this->member();
        $order = $this->placeEshopOrder($this->stockedProduct(), $member);
        app(ConfirmOrderPayment::class)->handle($this->paymentFor($order));
        $order->fresh()->update(['status' => Order::STATUS_PROCESSING]);

        $this->actingAs($member)->get(route('orders.receipt', $order))->assertOk();
    }

    public function test_a_guest_can_view_their_own_receipt_via_the_signed_link_once_paid(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        app(ConfirmOrderPayment::class)->handle($this->paymentFor($order));

        $this->get(URL::signedRoute('orders.receipt', $order))->assertOk()->assertSee($order->order_number);
        $this->get(route('orders.receipt', $order))->assertForbidden();
        $this->get(URL::temporarySignedRoute('orders.receipt', now()->subMinute(), $order))->assertForbidden();
    }

    public function test_a_member_cannot_view_another_members_receipt(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), $this->member());
        app(ConfirmOrderPayment::class)->handle($this->paymentFor($order));

        $this->actingAs($this->member())->get(route('orders.receipt', $order))->assertForbidden();
    }

    public function test_confirming_twice_is_idempotent_and_sends_one_receipt(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $payment = $this->paymentFor($order);

        app(ConfirmOrderPayment::class)->handle($payment);
        $paidAt = $payment->fresh()->paid_at;
        $this->travel(5)->minutes();
        app(ConfirmOrderPayment::class)->handle($payment);

        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        Notification::assertSentOnDemandTimes(OrderReceipt::class, 1);
    }
}
