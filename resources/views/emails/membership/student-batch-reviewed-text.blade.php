Aviation Club

Hello {!! str_replace(["\r", "\n"], ' ', $name) !!},

@if ($status === 'approved')
The student list you submitted on {!! $submittedOn !!} has been approved. {!! $count !!} student member(s) have been added to your School Club.

Each student will receive their own email with a secure, one-time link to create their password. Their membership is free — there are no joining or renewal fees.
@elseif ($status === 'more_details_required')
We need more information about the student list you submitted on {!! $submittedOn !!}:

{!! $requestMessage !!}

Please sign in and resubmit the corrected student list from Manage Student Members.
@else
The student list you submitted on {!! $submittedOn !!} was not approved. No student accounts were created. If you have any questions, please contact Aviation Club.
@endif

{!! $url !!}

Aviation Club
