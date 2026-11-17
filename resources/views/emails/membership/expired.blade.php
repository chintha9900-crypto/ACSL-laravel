<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your Aviation Club membership has expired</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;background:#f4f5f7;padding:24px 12px;">
<tr><td align="center" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
<tr><td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;">
<h1 style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 20px;font-size:22px;color:#0b2a4a;">Aviation Club</h1>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:17px;line-height:1.5;">Hello {{ $name }},</p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:17px;line-height:1.5;">Your membership (number <strong>{{ $number }}</strong>) has expired. Your membership benefits are currently unavailable.</p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;padding:12px 16px;background:#f4f5f7;border-radius:6px;font-size:17px;line-height:1.5;">You have until <strong>{{ $graceEndsOn }}</strong> to renew by bank transfer and keep your membership number and history.</p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:17px;line-height:1.5;">To renew, submit your payment reference and evidence on your membership page — the same process as any other renewal. Your access will be restored once an admin confirms your payment.</p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:17px;line-height:1.5;">Your account itself has not been removed.</p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:24px 0;text-align:center;"><a href="{{ $url }}" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;display:inline-block;background:#0b2a4a;color:#ffffff;text-decoration:none;font-size:17px;font-weight:bold;padding:12px 24px;border-radius:6px;">Renew now</a></p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:24px 0 0;font-size:16px;line-height:1.5;">Aviation Club</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
