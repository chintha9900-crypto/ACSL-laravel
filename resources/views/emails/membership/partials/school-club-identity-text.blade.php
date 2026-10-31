@if ($schoolClubIdentity ?? null)
Membership Category: {!! $schoolClubIdentity['category'] !!}
School: {!! str_replace(["\r", "\n"], ' ', $schoolClubIdentity['school'] ?? '—') !!}
School Club: {!! str_replace(["\r", "\n"], ' ', $schoolClubIdentity['club'] ?? '—') !!}

@endif
