<?php

namespace Tests\Concerns;

use App\Actions\Checkout\PlaceOrder;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\EshopSetting;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\PaymentBankAccount;
use App\Models\Product;
use App\Models\User;

/**
 * E-shop payment fixtures: the LKR bank account, PayHere sandbox credentials
 * and dummy-adapter settings that ACI would normally configure — test values
 * only, never real business data or credentials.
 */
trait CreatesEshopOrders
{
    protected const PAYHERE_TEST_MERCHANT_ID = '1200000';

    protected const PAYHERE_TEST_SECRET = 'test-merchant-secret';

    protected const DUMMY_TEST_EMAIL = 'tester@example.test';

    /**
     * Test fixture only — not a business price. The application has no default.
     */
    protected const TEST_DELIVERY_CHARGE = '350.00';

    /**
     * Configures the delivery charge explicitly, as an administrator would.
     */
    protected function configureDeliveryCharge(string $amount = self::TEST_DELIVERY_CHARGE): void
    {
        EshopSetting::current()->forceFill([
            'delivery_charge_configured' => true,
            'delivery_charge_amount' => $amount,
        ])->save();
    }

    protected function eshopBankAccount(): PaymentBankAccount
    {
        return PaymentBankAccount::query()->where('currency', 'LKR')->where('is_active', true)->first()
            ?? PaymentBankAccount::forceCreate([
                'label' => 'E-shop — LKR',
                'bank_name' => 'Test Bank',
                'account_name' => 'Aviation Club',
                'account_number' => '9876543210',
                'branch' => 'Colombo',
                'currency' => 'LKR',
                'is_active' => true,
            ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function checkoutDetails(array $overrides = []): array
    {
        return [
            'name' => 'Nimal Perera',
            'email' => 'nimal@example.test',
            'phone' => '+94 77 123 4567',
            'address_line1' => '12 Galle Road',
            'address_line2' => 'Apartment 3',
            'city' => 'Colombo',
            'postcode' => '00300',
            'country' => 'LK',
            'payment_method' => 'bank_transfer',
            ...$overrides,
        ];
    }

    protected function stockedProduct(float $price = 40, int $quantity = 10): Product
    {
        $product = Product::factory()->create(['price' => $price]);
        Inventory::factory()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $product;
    }

    /**
     * Places a real order through PlaceOrder (as a guest by default), so every
     * test starts from what checkout actually produces.
     *
     * @param  array<string, mixed>  $details
     */
    protected function placeEshopOrder(Product $product, ?User $user = null, int $quantity = 1, string $method = Order::METHOD_BANK_TRANSFER, array $details = []): Order
    {
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        // As PlaceOrderRequest::customer() would pass it: phone in E.164.
        $customer = $this->checkoutDetails(['phone' => '+94771234567', ...$details]);
        unset($customer['payment_method']);

        return app(PlaceOrder::class)->handle($cart, $user, $customer, $method);
    }

    protected function configurePayHere(): void
    {
        config([
            'payments.payhere.enabled' => true,
            'payments.payhere.merchant_id' => self::PAYHERE_TEST_MERCHANT_ID,
            'payments.payhere.merchant_secret' => self::PAYHERE_TEST_SECRET,
        ]);
    }

    /**
     * Enables the dummy adapter for the test host ("localhost") and test email.
     */
    protected function enableDummyPayments(string $host = 'localhost'): void
    {
        config([
            'payments.dummy.enabled' => true,
            'payments.dummy.allowed_hosts' => [$host],
            'payments.dummy.allowed_emails' => [self::DUMMY_TEST_EMAIL],
        ]);
    }

    /**
     * A PayHere notify payload signed with the test secret, per PayHere's
     * documented md5sig algorithm (computed independently of App\Payments\PayHere).
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function payHereNotification(string $orderId, string $amount, string $statusCode = '2', array $overrides = [], string $currency = 'LKR'): array
    {
        $merchantId = $overrides['merchant_id'] ?? self::PAYHERE_TEST_MERCHANT_ID;
        $signature = strtoupper(md5($merchantId.$orderId.$amount.$currency.$statusCode.strtoupper(md5(self::PAYHERE_TEST_SECRET))));

        return [
            'merchant_id' => $merchantId,
            'order_id' => $orderId,
            'payment_id' => '320025071278',
            'payhere_amount' => $amount,
            'payhere_currency' => $currency,
            'status_code' => $statusCode,
            'md5sig' => $signature,
            'method' => 'VISA',
            'status_message' => 'Successfully completed the payment.',
            ...$overrides,
        ];
    }
}
