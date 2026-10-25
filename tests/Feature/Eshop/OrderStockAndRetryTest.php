<?php

namespace Tests\Feature\Eshop;

use App\Actions\Orders\ReleaseOrderStock;
use App\Actions\Payments\ConfirmOrderPayment;
use App\Actions\Payments\FailOrderPayment;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class OrderStockAndRetryTest extends MysqlTestCase
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

    private function inventoryOf(Product $product): Inventory
    {
        return Inventory::query()->where('product_id', $product->id)->sole();
    }

    private function cancelAsAdmin(Order $order): TestResponse
    {
        return $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled']);
    }

    // --- stock ----------------------------------------------------------------------------

    public function test_stock_is_reserved_at_placement_and_kept_after_a_failed_attempt(): void
    {
        $product = $this->stockedProduct(10, 5);
        $order = $this->placeEshopOrder($product, null, 2);

        $this->assertSame(3, $this->inventoryOf($product)->quantity);

        app(FailOrderPayment::class)->handle($order->latestPayment(), 'declined');

        $this->assertSame(3, $this->inventoryOf($product)->quantity);
        $this->assertNull($order->fresh()->stock_released_at);
    }

    public function test_cancelling_an_unpaid_order_restores_stock_exactly_once_and_closes_its_payments(): void
    {
        $product = $this->stockedProduct(10, 5);
        $order = $this->placeEshopOrder($product, null, 2);

        $this->cancelAsAdmin($order)->assertSessionHasNoErrors();
        $this->cancelAsAdmin($order)->assertSessionHasErrors('status');
        app(ReleaseOrderStock::class)->handle($order, null, 'again');

        $this->assertSame(5, $this->inventoryOf($product)->quantity);
        $this->assertNotNull($order->fresh()->stock_released_at);
        $this->assertSame(Payment::STATUS_CANCELLED, $order->latestPayment()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'order.stock_released')->where('subject_id', $order->id)->count());
    }

    public function test_cancelling_an_order_under_review_also_closes_the_transfer(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX', 'evidence' => [$this->pdfUpload()]]);

        $this->cancelAsAdmin($order)->assertSessionHasNoErrors();

        $this->assertSame(Payment::STATUS_CANCELLED, $order->latestPayment()->status);
        $this->actingAs($this->admin())->get(route('admin.order-payments.index'))->assertDontSee($order->order_number);
    }

    public function test_a_paid_order_does_not_restore_stock_when_cancelled_or_refunded(): void
    {
        $product = $this->stockedProduct(10, 5);
        $order = $this->placeEshopOrder($product, null, 2);
        app(ConfirmOrderPayment::class)->handle($order->latestPayment());

        $this->cancelAsAdmin($order)->assertSessionHasNoErrors();

        $this->assertSame(3, $this->inventoryOf($product)->quantity);
        $this->assertNull($order->fresh()->stock_released_at);
        $this->assertSame(Payment::STATUS_PAID, $order->latestPayment()->status);

        $this->expectException(LogicException::class);
        app(ReleaseOrderStock::class)->handle($order, null, 'manual');
    }

    public function test_stock_for_a_deleted_product_is_skipped_safely(): void
    {
        $product = $this->stockedProduct(10, 5);
        $order = $this->placeEshopOrder($product);
        $order->items()->update(['product_id' => null]);

        $this->assertTrue(app(ReleaseOrderStock::class)->handle($order, null, 'cancelled'));
        $this->assertSame(4, $this->inventoryOf($product)->quantity);
    }

    // --- retries ----------------------------------------------------------------------------

    public function test_a_failed_payhere_attempt_can_be_retried_with_a_new_attempt_and_order_id(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(1500), null, 1, Order::METHOD_PAYHERE);
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '1850.00', '-2'))->assertOk();

        $this->get(URL::signedRoute('payment.show', $order))->assertSee('Try again: Card / online payment (PayHere)');
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'payhere'])->assertSessionHasNoErrors();
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'payhere'])->assertSessionHasNoErrors();

        $attempts = $order->payments()->orderBy('id')->get();
        $this->assertCount(2, $attempts, 'A second retry reuses the open attempt.');
        $this->assertSame($order->order_number.'-2', $attempts[1]->idempotency_key);
        $this->get(URL::signedRoute('payment.show', $order))->assertSee('name="order_id" value="'.$order->order_number.'-2"', false);

        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number.'-2', '1850.00'))->assertOk();
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_switching_methods_closes_the_open_attempt_without_duplicates(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());

        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'payhere'])->assertSessionHasNoErrors();

        $attempts = $order->payments()->orderBy('id')->get();
        $this->assertSame([Payment::STATUS_CANCELLED, Payment::STATUS_PENDING], $attempts->pluck('status')->all());
        $this->assertSame(Order::METHOD_PAYHERE, $order->fresh()->payment_method);
    }

    public function test_no_new_attempt_while_a_transfer_is_under_review_or_after_payment(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX', 'evidence' => [$this->pdfUpload()]]);

        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'payhere'])->assertSessionHasErrors('payment');

        $paid = $this->placeEshopOrder($this->stockedProduct());
        app(ConfirmOrderPayment::class)->handle($paid->latestPayment());
        $this->post(URL::signedRoute('payment.retry', $paid), ['payment_method' => 'payhere'])->assertSessionHasErrors('payment');

        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(1, $paid->payments()->count());
    }

    public function test_payhere_cannot_be_chosen_on_retry_without_a_valid_phone(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_BANK_TRANSFER, ['phone' => null]);

        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'payhere'])->assertSessionHasErrors('payment');

        $this->assertSame(1, $order->payments()->count());
    }

    public function test_retry_requires_order_access(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), $this->member());

        $this->actingAs($this->member())->post(route('payment.retry', $order), ['payment_method' => 'payhere'])->assertForbidden();
    }

    public function test_the_admin_order_page_shows_attempts_and_history(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX-HIST', 'evidence' => [$this->pdfUpload()]]);
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.order-payments.reject', $order->latestPayment()), ['reason' => 'Unreadable slip']);

        $this->actingAs($admin)->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('12 Galle Road')
            ->assertSee('Payment attempts')
            ->assertSee('TRX-HIST')
            ->assertSee('Unreadable slip')
            ->assertSee('employment-letter.pdf')
            ->assertSee('payment evidence submitted')
            ->assertSee('payment rejected');
    }
}
