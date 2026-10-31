Aviation Club

Hello {!! str_replace(["\r", "\n"], ' ', $name) !!},

Congratulations, and welcome to Aviation Club! You are now a member, and your membership is active.

Membership number: {!! $number !!}
@if ($schoolClubIdentity ?? null)
Membership Category: {!! $category !!}
School: {!! str_replace(["\r", "\n"], ' ', $schoolClubIdentity['school'] ?? '—') !!}
School Club: {!! str_replace(["\r", "\n"], ' ', $schoolClubIdentity['club'] ?? '—') !!}
@else
Category: {!! $category !!}
@endif
@if ($months && $startsOn && $expiresOn)
@if ($schoolClub ?? false)
Free membership — no joining or renewal fees. Valid from {!! $startsOn !!} to {!! $expiresOn !!}, renewable free of charge.
@else
Your first {!! $months !!} month(s) are free, valid from {!! $startsOn !!} to {!! $expiresOn !!}.
@endif
@endif

Your digital membership card, with a QR code you can use to verify your membership, is available on your membership page once you have signed in.

Check your email for a separate message with a secure, one-time link to create your account password.

Once you have created your password, sign in to the member portal at {!! $loginUrl !!} using your registered email address, {!! str_replace(["\r", "\n"], '', $email) !!}, as your username.

Aviation Club
