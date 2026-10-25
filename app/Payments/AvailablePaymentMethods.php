<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\PaymentBankAccount;
use Illuminate\Http\Request;

/**
 * The e-shop payment methods that can be offered for a request: bank transfer
 * when bank details are configured for the currency, PayHere when its
 * sandbox credentials are configured, and the dummy method only where
 * DummyPaymentGate allows it.
 */
class AvailablePaymentMethods
{
    public function __construct(
        private readonly PayHere $payHere,
        private readonly DummyPaymentGate $dummyGate,
    ) {}

    /**
     * @return list<string>
     */
    public function for(Request $request, string $currency = 'LKR'): array
    {
        $methods = [];

        if (PaymentBankAccount::query()->activeForCurrency($currency)->exists()) {
            $methods[] = Order::METHOD_BANK_TRANSFER;
        }

        if ($this->payHere->isConfigured()) {
            $methods[] = Order::METHOD_PAYHERE;
        }

        if ($this->dummyGate->allows($request)) {
            $methods[] = Order::METHOD_DUMMY;
        }

        return $methods;
    }
}
