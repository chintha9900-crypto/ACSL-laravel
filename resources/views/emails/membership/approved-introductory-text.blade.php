Aviation Club

Hello {!! str_replace(["\r", "\n"], ' ', $name) !!},

Congratulations — your {!! $category !!} membership application has been approved.

@include('emails.membership.partials.school-club-identity-text')
@if ($schoolClub ?? false)
School Club membership is free — there are no joining or renewal fees. It runs for {!! $months !!} month(s) at a time and can be renewed free of charge.
@else
As a new member, your first {!! $months !!} month(s) of membership are free of charge — no payment required.
@endif

We will be in touch shortly to activate your membership and send you your membership number and account details.

Aviation Club
