Aviation Club

Hello {!! str_replace(["\r", "\n"], ' ', $name) !!},

Your membership (number {!! $number !!}) expires in {!! $daysRemaining !!} day(s), on {!! $expiresOn !!}.
@if ($fee)

The renewal fee is {!! $fee !!}, payable by bank transfer.
@endif

@if ($schoolClub ?? false)
School Club membership is free. There is no automatic renewal — renew it free of charge from your membership page.
@else
There is no automatic renewal — your membership will not renew unless you pay and an admin confirms it.
@endif

{!! $url !!}

Aviation Club
