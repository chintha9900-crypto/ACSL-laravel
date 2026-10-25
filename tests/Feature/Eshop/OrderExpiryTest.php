<?php

namespace Tests\Feature\Eshop;

use App\Actions\Orders\ExpireUnpaidOrder;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Models\Product;
use App\Notifications\Orders\OrderExpired;
use App\Notifications\Orders\OrderReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class OrderExpiryTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('private');
        $this->eshopBankAccount();
        $this->configureDeliveryCharge();
        $this->configurePayHere();
    }

    private function stockOf(Product $product): int
    {
        return Inventory::query()->where('product_id', $product->id)->value('quantity');
    }

    private function expire(): void
    {
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
    }

    public function test_deadlines_are_30_minutes_for_payhere_and_72_hours_for_bank_transfer_from_creation(): void
    {
        $this->freezeTime();
        $payHere = $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_PAYHERE);
        $bank = $this->placeEshopOrder($this->stockedProduct());

        $this->assertTrue($payHere->fresh()->expires_at->equalTo($payHere->created_at->copy()->addMinutes(30)));
        $this->assertTrue($bank->fresh()->expires_at->equalTo($bank->created_at->copy()->addHours(72)));
    }

    public function test_a_payhere_order_expires_after_30_minutes_and_releases_stock_once(): void
    {
        $product = $this->stockedProduct(40, 5);
        $order = $this->placeEshopOrder($product, null, 2, Order::METHOD_PAYHERE);
        $this->assertSame(3, $this->stockOf($product));

        $this->travel(29)->minutes();
        $this->expire();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);

        $this->travel(2)->minutes();
        $this->expire();
        $this->expire();
        app(ExpireUnpaidOrder::class)->handle($order);

        $order->refresh();
        $this->assertSame(Order::STATUS_EXPIRED, $order->status);
        $this->assertSame(5, $this->stockOf($product), 'Released exactly once across repeated runs.');
        $this->assertSame(Payment::STATUS_CANCELLED, $order->latestPayment()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'order.stock_released')->where('subject_id', $order->id)->count());
        Notification::assertSentOnDemandTimes(OrderExpired::class, 1);
    }

    public function test_a_bank_transfer_order_expires_after_72_hours_not_before(): void
    {
        $product = $this->stockedProduct(40, 5);
        $order = $this->placeEshopOrder($product);

        $this->travel(71)->hours();
        $this->expire();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);

        $this->travel(61)->minutes();
        $this->expire();
        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
        $this->assertSame(5, $this->stockOf($product));
    }

    public function test_an_order_with_transfer_evidence_under_review_is_not_expired(): void
    {
        $product = $this->stockedProduct(40, 5);
        $order = $this->placeEshopOrder($product);
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX', 'evidence' => [$this->pdfUpload()]]);

        $this->travel(80)->hours();
        $this->expire();

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status, 'An admin decides; the money may already have been sent.');
        $this->assertSame(4, $this->stockOf($product));

        $this->actingAs($this->admin())->post(route('admin.order-payments.approve', $order->latestPayment()))->assertSessionHasNoErrors();
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_reviewers_see_the_age_and_overdue_status_of_transfers_under_review(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX-AGE', 'evidence' => [$this->pdfUpload()]]);
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('admin.order-payments.index'))
            ->assertSee('Waiting')
            ->assertDontSee('data-review-overdue', false);

        $this->travel(75)->hours();
        $this->expire();

        $this->actingAs($editor)->get(route('admin.order-payments.index'))
            ->assertOk()
            ->assertSee('TRX-AGE')
            ->assertSee('Waiting 3 days')
            ->assertSee('Past payment deadline')
            ->assertSee('not auto-expired while under review');
        $this->actingAs($editor)->get(route('admin.orders.show', $order))->assertSee('not expired automatically because transfer evidence is under review');
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_a_rejected_transfer_past_its_deadline_then_expires(): void
    {
        $product = $this->stockedProduct(40, 5);
        $order = $this->placeEshopOrder($product);
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX', 'evidence' => [$this->pdfUpload()]]);
        $this->travel(80)->hours();
        $this->expire();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status, 'Protected while under review.');

        $this->actingAs($this->admin())->post(route('admin.order-payments.reject', $order->latestPayment()), ['reason' => 'Not received']);
        $this->expire();

        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status, 'Once no longer under review, the passed deadline applies.');
        $this->assertSame(5, $this->stockOf($product));
    }

    public function test_repeated_method_switches_never_move_the_deadline_beyond_the_policy_from_creation(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_PAYHERE);
        $created = $order->created_at->copy();

        $this->travel(5)->minutes();
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'bank_transfer'])->assertSessionHasNoErrors();
        $this->assertTrue($order->fresh()->expires_at->equalTo($created->copy()->addHours(72)));

        $this->travel(5)->minutes();
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'payhere'])->assertSessionHasNoErrors();
        $this->assertTrue($order->fresh()->expires_at->equalTo($created->copy()->addMinutes(30)), 'Back to PayHere: 30 minutes from creation, not from the switch.');

        $this->travel(5)->minutes();
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'bank_transfer'])->assertSessionHasNoErrors();
        $this->assertTrue($order->fresh()->expires_at->equalTo($created->copy()->addHours(72)), 'Never later than 72 hours from creation, however often it switches.');

        $this->travel(2)->hours();
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'payhere'])->assertSessionHasErrors('payment');
        $this->assertTrue($order->fresh()->expires_at->equalTo($created->copy()->addHours(72)));
        $this->assertSame(Order::METHOD_BANK_TRANSFER, $order->fresh()->payment_method);

        $this->travel(71)->hours();
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'bank_transfer'])->assertSessionHasErrors('payment');
        $this->expire();
        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
    }

    public function test_the_payhere_option_is_not_offered_after_its_window_from_creation(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());

        $this->get(URL::signedRoute('payment.show', $order))->assertSee('Card / online payment (PayHere)');

        $this->travel(31)->minutes();
        $this->get(URL::signedRoute('payment.show', $order))->assertDontSee('Pay by Card / online payment (PayHere)');
    }

    public function test_a_paid_or_cancelled_order_is_never_expired_and_stock_is_not_released_twice(): void
    {
        $product = $this->stockedProduct(40, 5);
        $paid = $this->placeEshopOrder($product, null, 1, Order::METHOD_PAYHERE);
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($paid->order_number, '390.00'))->assertOk();
        $cancelled = $this->placeEshopOrder($product, null, 1, Order::METHOD_PAYHERE);
        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $cancelled), ['status' => 'cancelled']);

        $this->travel(1)->hours();
        $this->expire();

        $this->assertSame(Order::STATUS_PAID, $paid->fresh()->status);
        $this->assertSame(Order::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(4, $this->stockOf($product), 'Paid order keeps its stock; cancelled order released once.');
    }

    public function test_a_retry_never_extends_the_deadline(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_PAYHERE);
        $deadline = $order->fresh()->expires_at;

        $this->travel(20)->minutes();
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '390.00', '-2'))->assertOk();
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'payhere'])->assertSessionHasNoErrors();

        $this->assertTrue($order->fresh()->expires_at->equalTo($deadline));

        $this->travel(11)->minutes();
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'bank_transfer'])->assertSessionHasErrors('payment');
        $this->expire();
        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
    }

    public function test_switching_to_bank_transfer_uses_the_bank_window_measured_from_creation(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_PAYHERE);

        $this->travel(10)->minutes();
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'bank_transfer'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertTrue($order->expires_at->equalTo($order->created_at->copy()->addHours(72)));
    }

    public function test_no_payment_can_start_after_the_deadline_even_before_the_scheduler_runs(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());

        $this->travel(73)->hours();

        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX', 'evidence' => [$this->pdfUpload()]])->assertSessionHasErrors('payment');
        $this->get(URL::signedRoute('payment.show', $order))->assertSee('payment deadline for this order has passed')->assertDontSee('Submit transfer details');
    }

    public function test_a_late_payhere_success_after_expiry_does_not_reopen_the_order(): void
    {
        $product = $this->stockedProduct(40, 5);
        $order = $this->placeEshopOrder($product, null, 1, Order::METHOD_PAYHERE);
        $this->travel(31)->minutes();
        $this->expire();

        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '390.00'))->assertOk();

        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PAID, $order->latestPayment()->status, 'The money is recorded.');
        $this->assertSame(PaymentReview::TYPE_LATE_PAYMENT, PaymentReview::query()->sole()->type);
        $this->assertSame(5, $this->stockOf($product), 'Stock is not re-reserved or released again.');
        Notification::assertNotSentTo(Notification::route('mail', $order->customer_email), OrderReceipt::class);
    }

    public function test_concurrent_cancellation_then_expiry_releases_stock_once(): void
    {
        $product = $this->stockedProduct(40, 5);
        $order = $this->placeEshopOrder($product, null, 1, Order::METHOD_PAYHERE);
        $stale = Order::query()->find($order->id);

        $this->travel(31)->minutes();
        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled']);

        $this->assertFalse(app(ExpireUnpaidOrder::class)->handle($stale), 'Re-checked under the lock: no longer pending.');
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(5, $this->stockOf($product));
    }

    public function test_the_expiry_command_is_scheduled_every_five_minutes(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('*/5 * * * *  php artisan orders:expire-unpaid')
            ->assertSuccessful();
    }
}
