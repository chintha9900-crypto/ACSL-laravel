<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>We've received your Aviation Club International application</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 20px;font-size:20px;color:#0b2a4a;">Aviation Club International</h1>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hello {{ $name }},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Thank you for applying for {{ $category }} membership with Aviation Club International. Your application reference is <strong>{{ $reference }}</strong>.</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">We will email you whenever there is an update. You can check your application's status at any time using the button below.</p>
<p style="margin:24px 0;text-align:center;"><a href="{{ $url }}" style="display:inline-block;background:#0b2a4a;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:12px 24px;border-radius:6px;">View application status</a></p>
<p style="margin:0 0 16px;font-size:14px;line-height:1.5;">If the button does not work, copy this address into your browser:<br><a href="{{ $url }}" style="color:#0b2a4a;word-break:break-all;">{{ $url }}</a></p>
<p style="margin:24px 0 0;font-size:14px;line-height:1.5;">Aviation Club International</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
