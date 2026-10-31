{{-- School Club emails only: the membership's category, school and club. --}}
@if ($schoolClubIdentity ?? null)
<p style="margin:0 0 16px;padding:12px 16px;background:#f4f5f7;border-radius:6px;font-size:15px;line-height:1.5;">
Membership Category: {{ $schoolClubIdentity['category'] }}<br>
School: {{ $schoolClubIdentity['school'] ?? '—' }}<br>
School Club: {{ $schoolClubIdentity['club'] ?? '—' }}
</p>
@endif
