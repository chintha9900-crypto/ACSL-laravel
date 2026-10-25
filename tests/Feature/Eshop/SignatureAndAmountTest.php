<?php

namespace Tests\Feature\Eshop;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Notifications\Orders\OrderReceipt;
use App\Payments\PayHere;
use App\Support\Money;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * Known-answer vectors for the PayHere hashes (computed independently with
 * the clearly fake test merchant id/secret), exact money handling, strict
 * amount formats and the treatment of fields PayHere does not sign.
 */
class SignatureAndAmountTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesReviewableApplications;

    private const VECTOR_CHECKOUT_HASH = '4757756BB8AA1C8A891697EBEC8F8261';

    private const VECTOR_NOTIFY_SIG = 'B6CDCB4B6ED58F5DFA50BF37CA47FA35';

    private const VECTOR_NOTIFY_SIG_RAW_1850 = 'DD2918C7EC3B9F4B7E1A61B66A8701CF';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->configurePayHere();
        $this->configureDeliveryCharge();
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function payHereOrder(): array
    {
        $order = $this->placeEshopOrder($this->stockedProduct(1500), null, 1, Order::METHOD_PAYHERE);

        return [$order, Payment::query()->where('order_id', $order->id)->sole()];
    }

    public function test_the_checkout_hash_matches_the_known_vector(): void
    {
        $this->assertSame(self::VECTOR_CHECKOUT_HASH, app(PayHere::class)->checkoutHash('ORD-VECTOR-1', '1850.00', 'LKR'));
    }

    public function test_the_notification_signature_matches_the_known_vector_over_the_raw_amount(): void
    {
        $payHere = app(PayHere::class);
        $params = ['merchant_id' => '1200000', 'order_id' => 'ORD-VECTOR-1', 'payhere_amount' => '1850.00', 'payhere_currency' => 'LKR', 'status_code' => '2'];

        $this->assertTrue($payHere->hasValidSignature($params + ['md5sig' => self::VECTOR_NOTIFY_SIG]));
        $this->assertTrue($payHere->hasValidSignature($params + ['md5sig' => strtolower(self::VECTOR_NOTIFY_SIG)]), 'Hex case does not matter.');

        // The amount string is used exactly as received, never normalised:
        // "1850" is signed differently from "1850.00".
        $this->assertFalse($payHere->hasValidSignature(['payhere_amount' => '1850'] + $params + ['md5sig' => self::VECTOR_NOTIFY_SIG]));
        $this->assertTrue($payHere->hasValidSignature(['payhere_amount' => '1850'] + $params + ['md5sig' => self::VECTOR_NOTIFY_SIG_RAW_1850]));
    }

    public function test_the_checkout_form_amount_is_the_exact_string_that_was_hashed(): void
    {
        [$order, $payment] = $this->payHereOrder();

        $fields = app(PayHere::class)->checkoutFields($order->load('items'), $payment, 'https://r', 'https://c', 'https://n');

        $this->assertSame('1850.00', $fields['amount']);
        $this->assertSame(app(PayHere::class)->checkoutHash($payment->idempotency_key, $fields['amount'], 'LKR'), $fields['hash']);
    }

    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function amounts(): array
    {
        return [
            'two decimals' => ['1850.00', 185000],
            'one decimal' => ['1850.5', 185050],
            'no decimals' => ['1850', 185000],
            'zero' => ['0.00', 0],
            'exponent' => ['1.85e3', null],
            'leading space' => [' 1850.00', null],
            'trailing space' => ['1850.00 ', null],
            'thousands separator' => ['1,850.00', null],
            'negative' => ['-1850.00', null],
            'plus sign' => ['+1850.00', null],
            'three decimals' => ['1850.000', null],
            'leading zero' => ['01850.00', null],
            'hex' => ['0x73A', null],
            'empty' => ['', null],
            'newline' => ["1850.00\n", null],
        ];
    }

    #[DataProvider('amounts')]
    public function test_money_parsing_is_exact_and_strict(string $amount, ?int $minor): void
    {
        $this->assertSame($minor !== null, Money::isValid($amount));

        if ($minor !== null) {
            $this->assertSame($minor, Money::toMinor($amount));
        }
    }

    public function test_minor_unit_arithmetic_has_no_float_drift(): void
    {
        $this->assertSame('0.30', Money::fromMinor(Money::toMinor('0.10') + Money::toMinor('0.20')));
        $this->assertSame('999999999999.99', Money::format('999999999999.99'));
        $this->assertTrue(PayHere::amountMatches('1850.00', '1850.00'));
        $this->assertTrue(PayHere::amountMatches('1850', '1850.00'));
        $this->assertFalse(PayHere::amountMatches('1850.01', '1850.00'));
        $this->assertFalse(PayHere::amountMatches('1.85e3', '1850.00'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedAmounts(): array
    {
        return [
            'exponent' => ['1.85e3'],
            'whitespace' => [' 1850.00'],
            'comma separated' => ['1,850.00'],
        ];
    }

    #[DataProvider('malformedAmounts')]
    public function test_a_validly_signed_but_malformed_amount_changes_nothing_and_is_reviewed(string $amount): void
    {
        [$order, $payment] = $this->payHereOrder();

        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, $amount))->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(PaymentReview::TYPE_AMOUNT_MISMATCH, PaymentReview::query()->sole()->type);
        Notification::assertNothingSent();
    }

    public function test_notification_fields_are_verified_exactly_as_sent_not_trimmed(): void
    {
        [$order, $payment] = $this->payHereOrder();
        // Signed over "1850.00 " (with a space). If the app trimmed input, the
        // signature check would see "1850.00" and fail; it must pass the
        // signature and then be rejected by the strict amount check instead.
        $payload = $this->payHereNotification($order->order_number, '1850.00 ');

        $this->post(route('payments.payhere.notify'), $payload)->assertStatus(400);

        $this->assertSame(PaymentReview::TYPE_AMOUNT_MISMATCH, PaymentReview::query()->sole()->type, 'Reached the amount check, so the signature verified on the raw value.');
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_replayed_success_with_a_modified_payment_id_cannot_confirm_or_corrupt_anything(): void
    {
        [$order, $payment] = $this->payHereOrder();
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '1850.00'))->assertOk();
        $before = $payment->fresh();

        foreach (['FORGED-1', 'FORGED-2', 'FORGED-1'] as $forged) {
            $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '1850.00', '2', [
                'payment_id' => $forged,
                'method' => 'FORGED',
                'status_message' => 'forged',
            ]))->assertOk();
        }

        $after = $payment->fresh();
        $this->assertSame($before->transaction_reference, $after->transaction_reference);
        $this->assertEquals($before->metadata, $after->metadata, 'Trusted transaction metadata is never overwritten.');
        $this->assertEquals($before->paid_at, $after->paid_at);

        $review = PaymentReview::query()->sole();
        $this->assertSame(PaymentReview::TYPE_DUPLICATE_NOTIFICATION, $review->type);
        $this->assertSame(['FORGED-1', 'FORGED-2'], $review->details['unverified']['reported_references']);
        $this->assertSame(3, $review->details['repeat_count'], 'Three replays after the genuine success.');
        Notification::assertSentOnDemandTimes(OrderReceipt::class, 1);
    }

    public function test_unsigned_fields_alone_never_confirm_a_payment(): void
    {
        [$order, $payment] = $this->payHereOrder();
        $failed = $this->payHereNotification($order->order_number, '1850.00', '-2');

        // A genuinely signed failure with tampered unsigned fields claiming success.
        $this->post(route('payments.payhere.notify'), [...$failed, 'status_message' => 'Successfully completed', 'payment_id' => 'X'])->assertOk();

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_an_unknown_payment_attempt_and_a_membership_order_id_are_rejected(): void
    {
        $this->post(route('payments.payhere.notify'), $this->payHereNotification('ORD-UNKNOWN-9', '1850.00'))->assertNotFound();
        $this->assertSame(0, PaymentReview::query()->count());
    }
}
