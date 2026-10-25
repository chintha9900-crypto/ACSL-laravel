@use('Illuminate\Support\Str')
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Welcome to Aviation Club</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 20px;font-size:20px;color:#0b2a4a;">Aviation Club</h1>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hello {{ $name }},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Congratulations, and welcome to Aviation Club! You are now a member, and your membership is active.</p>
<p style="margin:0 0 16px;padding:12px 16px;background:#f4f5f7;border-radius:6px;font-size:15px;line-height:1.5;">
Membership number: <strong>{{ $number }}</strong><br>
Category: {{ $category }}
@if ($months && $startsOn && $expiresOn)
@if ($schoolClub ?? false)
<br>Free membership — no joining or renewal fees. Valid from {{ $startsOn }} to {{ $expiresOn }}, renewable free of charge.
@else
<br>Your first {{ $months }} {{ Str::plural('month', $months) }} are free, valid from {{ $startsOn }} to {{ $expiresOn }}.
@endif
@endif
</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Your digital membership card, with a QR code you can use to verify your membership, is available on your membership page once you have signed in.</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Check your email for a separate message with a secure, one-time link to create your account password.</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Once you have created your password, sign in to the member portal at <a href="{{ $loginUrl }}" style="color:#0b2a4a;word-break:break-all;">{{ $loginUrl }}</a> using your registered email address, <strong>{{ $email }}</strong>, as your username.</p>
<p style="margin:24px 0 0;font-size:14px;line-height:1.5;">Aviation Club</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
