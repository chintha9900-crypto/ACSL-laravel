<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Payments\AvailablePaymentMethods;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Checkout details. Shape only: prices, totals and stock are always
 * re-derived by PlaceOrder. The phone is optional for bank transfer but
 * required (and must be a valid international number) for PayHere, which
 * needs it for the hosted checkout.
 */
class PlaceOrderRequest extends FormRequest
{
    /**
     * E.164: "+", a country code that does not start with 0, at most 15 digits.
     */
    private const E164_PATTERN = '/^\+[1-9][0-9]{6,14}$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $methods = app(AvailablePaymentMethods::class)->for($this);

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => [
                Rule::requiredIf(fn (): bool => $this->input('payment_method') === Order::METHOD_PAYHERE),
                'nullable', 'string', 'max:40', $this->internationalPhone(),
            ],
            'address_line1' => ['required', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'city' => ['required', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]*$/'],
            'country' => ['required', 'string', Rule::in(array_keys((array) config('payments.delivery_countries')))],
            'payment_method' => ['required', 'string', Rule::in($methods)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'A phone number is required to pay with PayHere.',
            'address_line1.required' => 'Enter the delivery address.',
            'city.required' => 'Enter the delivery city or town.',
            'postcode.regex' => 'Enter a valid postcode.',
            'country.in' => 'We can only deliver to the countries listed.',
            'payment_method.required' => 'Choose a payment method.',
            'payment_method.in' => 'That payment method is not available.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'address_line1' => 'address line 1',
            'address_line2' => 'address line 2',
        ];
    }

    /**
     * The validated details in the form PlaceOrder stores: trimmed, the phone
     * normalised to E.164, empty optional values as null.
     *
     * @return array<string, ?string>
     */
    public function customer(): array
    {
        $phone = $this->safe()->string('phone')->trim()->toString();

        return [
            'name' => $this->safe()->string('name')->trim()->toString(),
            'email' => $this->safe()->string('email')->trim()->toString(),
            'phone' => $phone === '' ? null : '+'.preg_replace('/\D/', '', $phone),
            'address_line1' => $this->safe()->string('address_line1')->trim()->toString(),
            'address_line2' => $this->safe()->string('address_line2')->trim()->toString() ?: null,
            'city' => $this->safe()->string('city')->trim()->toString(),
            'postcode' => $this->safe()->string('postcode')->trim()->upper()->toString() ?: null,
            'country' => $this->safe()->string('country')->toString(),
        ];
    }

    private function internationalPhone(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $value = trim($value);

            if (preg_match('/^\+[0-9\s().-]+$/', $value) !== 1
                || preg_match(self::E164_PATTERN, '+'.preg_replace('/\D/', '', $value)) !== 1) {
                $fail('Enter the phone number in international format, starting with + and the country code (for example +94 77 123 4567).');
            }
        };
    }
}
