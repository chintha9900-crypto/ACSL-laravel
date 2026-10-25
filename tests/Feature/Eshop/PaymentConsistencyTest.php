<?php

namespace Tests\Feature\Eshop;

use App\Actions\Orders\UpdateOrderStatus;
use App\Actions\Payments\ConfirmOrderPayment;
use App\Actions\Payments\ReviewOrderBankTransfer;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Notifications\Orders\OrderReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use LogicException;
use RuntimeException;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

/**
 * Transaction consistency and race outcomes. True parallel execution is not
 * possible inside the test suite (each test runs in one database
 * transaction), so races are reproduced as the interleavings the row locks
 * serialise them into: stale in-memory models acting after another actor
 * already committed, in both orders.
 */
class PaymentConsistencyTest extends MysqlTestCase
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

    public function test_no_receipt_is_sent_when_the_surrounding_transaction_rolls_back(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $payment = $order->latestPayment();

        try {
            DB::transaction(function () use ($payment): void {
                app(ConfirmOrderPayment::class)->handle($payment);

                throw new RuntimeException('Simulated failure after confirmation');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_stale_payment_model_cannot_confirm_twice_or_send_a_second_receipt(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $staleA = Payment::query()->find($order->latestPayment()->id);
        $staleB = Payment::query()->find($staleA->id);

        app(ConfirmOrderPayment::class)->handle($staleA);
        $paidAt = $staleA->fresh()->paid_at;
        $this->travel(1)->minutes();
        app(ConfirmOrderPayment::class)->handle($staleB);

        $this->assertEquals($paidAt, $staleB->fresh()->paid_at);
        Notification::assertSentOnDemandTimes(OrderReceipt::class, 1);
    }

    public function test_two_admins_approving_the_same_transfer_confirm_it_once(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX', 'evidence' => [$this->pdfUpload()]]);
        $first = Payment::query()->find($order->latestPayment()->id);
        $second = Payment::query()->find($first->id);

        $outcomeA = app(ReviewOrderBankTransfer::class)->approve($first, $this->admin());
        $outcomeB = app(ReviewOrderBankTransfer::class)->approve($second, $this->editor());

        $this->assertSame(ReviewOrderBankTransfer::APPROVED, $outcomeA);
        $this->assertSame(ReviewOrderBankTransfer::ALREADY_APPROVED, $outcomeB);
        Notification::assertSentOnDemandTimes(OrderReceipt::class, 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'payment.confirmed')->where('subject_id', $first->id)->count());
    }

    public function test_cancellation_racing_a_success_notification_cancellation_first(): void
    {
        $product = $this->stockedProduct(40, 5);
        $order = $this->placeEshopOrder($product, null, 1, Order::METHOD_PAYHERE);

        app(UpdateOrderStatus::class)->handle(Order::query()->find($order->id), Order::STATUS_CANCELLED, $this->admin());
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '390.00'))->assertOk();

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(5, Inventory::query()->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(PaymentReview::TYPE_LATE_PAYMENT, PaymentReview::query()->sole()->type);
        Notification::assertNothingSent();
    }

    public function test_cancellation_racing_a_success_notification_notification_first(): void
    {
        $product = $this->stockedProduct(40, 5);
        $order = $this->placeEshopOrder($product, null, 1, Order::METHOD_PAYHERE);
        $staleOrder = Order::query()->find($order->id); // the admin's page, loaded before payment

        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '390.00'))->assertOk();
        app(UpdateOrderStatus::class)->handle($staleOrder, Order::STATUS_CANCELLED, $this->admin());

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status, 'paid → cancelled is an allowed admin transition');
        $this->assertSame(4, Inventory::query()->where('product_id', $product->id)->value('quantity'), 'A paid order never gets stock back automatically.');
        $this->assertSame(Payment::STATUS_PAID, $order->latestPayment()->status);
    }

    public function test_approval_racing_cancellation_in_either_order_never_marks_a_cancelled_order_paid(): void
    {
        $orderA = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $orderA), ['reference' => 'A', 'evidence' => [$this->pdfUpload()]]);
        $staleTransfer = Payment::query()->find($orderA->latestPayment()->id);

        // Cancellation first: it closes the transfer and puts it in review, so
        // the approval (from a page loaded earlier) is refused.
        app(UpdateOrderStatus::class)->handle($orderA, Order::STATUS_CANCELLED, $this->admin());

        try {
            app(ReviewOrderBankTransfer::class)->approve($staleTransfer, $this->admin());
            $this->fail('Approval of a closed transfer must be refused.');
        } catch (PaymentCannotBeReviewedException) {
        }

        $this->assertSame(Order::STATUS_CANCELLED, $orderA->fresh()->status);
        $this->assertSame(Payment::STATUS_CANCELLED, $staleTransfer->fresh()->status);
        $this->assertSame(PaymentReview::TYPE_TRANSFER_CONFLICT, PaymentReview::query()->sole()->type);

        $orderB = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $orderB), ['reference' => 'B', 'evidence' => [$this->pdfUpload()]]);
        $staleOrderB = Order::query()->find($orderB->id);
        app(ReviewOrderBankTransfer::class)->approve($orderB->latestPayment(), $this->admin());
        app(UpdateOrderStatus::class)->handle($staleOrderB, Order::STATUS_CANCELLED, $this->admin());

        $this->assertSame(Payment::STATUS_PAID, $orderB->latestPayment()->status, 'The verified payment stays recorded.');
        $this->assertNull($orderB->fresh()->stock_released_at);
    }

    public function test_a_failure_inside_cancellation_rolls_back_every_change(): void
    {
        $product = $this->stockedProduct(40, 5);
        $order = $this->placeEshopOrder($product);
        // An inconsistent state that makes the stock release refuse mid-way.
        $order->latestPayment()->forceFill(['status' => Payment::STATUS_PAID])->save();

        try {
            app(UpdateOrderStatus::class)->handle($order, Order::STATUS_CANCELLED, $this->admin());
            $this->fail('Expected the stock release to refuse.');
        } catch (LogicException) {
        }

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status, 'The status change rolled back with it.');
        $this->assertSame(4, Inventory::query()->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'order.status_changed')->where('subject_id', $order->id)->count());
    }

    public function test_concurrent_duplicate_success_notifications_settle_to_one_confirmation(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_PAYHERE);
        $payload = $this->payHereNotification($order->order_number, '390.00');

        // Serialised by the order lock: the second sees the first's commit.
        $this->post(route('payments.payhere.notify'), $payload)->assertOk();
        $this->post(route('payments.payhere.notify'), $payload)->assertOk();

        $this->assertSame(1, DB::table('audit_logs')->where('event', 'order.paid')->where('subject_id', $order->id)->count());
        $this->assertSame(0, PaymentReview::query()->count());
        Notification::assertSentOnDemandTimes(OrderReceipt::class, 1);
    }
}
