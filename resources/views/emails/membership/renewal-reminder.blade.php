@use('Illuminate\Support\Str')
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your Aviation Club membership expires soon</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 20px;font-size:20px;color:#0b2a4a;">Aviation Club</h1>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hello {{ $name }},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Your membership (number <strong>{{ $number }}</strong>) expires in <strong>{{ $daysRemaining }} {{ Str::plural('day', $daysRemaining) }}</strong>, on {{ $expiresOn }}.</p>
@if ($fee)
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">The renewal fee is {{ $fee }}, payable by bank transfer.</p>
@endif
@include('emails.membership.partials.school-club-identity')
@if ($schoolClub ?? false)
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">School Club membership is free. There is no automatic renewal — renew it free of charge from your membership page.</p>
@else
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">There is no automatic renewal — your membership will not renew unless you pay and an admin confirms it.</p>
@endif
<p style="margin:24px 0;text-align:center;"><a href="{{ $url }}" style="display:inline-block;background:#0b2a4a;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:12px 24px;border-radius:6px;">Renew now</a></p>
<p style="margin:24px 0 0;font-size:14px;line-height:1.5;">Aviation Club</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
