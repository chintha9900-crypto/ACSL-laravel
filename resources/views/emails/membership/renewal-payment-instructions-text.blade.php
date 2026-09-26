Aviation Club International

Hello {!! str_replace(["\r", "\n"], ' ', $name) !!},

It's time to renew your membership (number {!! $number !!}) for another {!! $months !!} months.

Amount due: {!! $currency !!} {!! $amount !!}

Please pay by bank transfer to:
{!! $bank->bank_name !!}
Account name: {!! $bank->account_name !!}
Account number: {!! $bank->account_number !!}
@if ($bank->branch)Branch: {!! $bank->branch !!}
@endif
@if ($bank->sort_code)Sort code: {!! $bank->sort_code !!}
@endif
@if ($bank->iban)IBAN: {!! $bank->iban !!}
@endif
@if ($bank->swift_bic)SWIFT/BIC: {!! $bank->swift_bic !!}
@endif
@if ($bank->instructions)
{!! $bank->instructions !!}
@endif

Once you have paid, submit your payment reference and evidence on your membership page. There is no automatic renewal — your membership will not renew unless you pay and an admin confirms it.

{!! $url !!}

Aviation Club International
