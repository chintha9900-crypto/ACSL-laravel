<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up your Aviation Club account</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;background:#f4f5f7;padding:24px 12px;">
<tr><td align="center" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
<tr><td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;">
<h1 style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 20px;font-size:20px;color:#0b2a4a;">Aviation Club</h1>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:15px;line-height:1.5;">Hello {{ $name }},</p>
@if ($editorInvitation ?? false)
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:15px;line-height:1.5;">You have been invited to join Aviation Club as an editor. To finish setting up your account, please create your password using the button below.</p>
@else
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:15px;line-height:1.5;">Your Aviation Club membership is now active. To finish setting up your account, please create your password using the button below.</p>
@endif
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:24px 0;text-align:center;"><a href="{{ $url }}" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;display:inline-block;background:#0b2a4a;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:12px 24px;border-radius:6px;">Create your password</a></p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:14px;line-height:1.5;">This link can be used <strong>once only</strong> and expires on <strong>{{ $expiresAt }}</strong>. After you have created your password, or once it has expired, it will stop working.</p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:14px;line-height:1.5;">If the button does not work, copy this address into your browser:<br><a href="{{ $url }}" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;color:#0b2a4a;word-break:break-all;">{{ $url }}</a></p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:14px;line-height:1.5;">After creating your password, sign in to the member portal at <a href="{{ route('login') }}" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;color:#0b2a4a;word-break:break-all;">{{ route('login') }}</a> using your registered email address (the address this email was sent to) as your username.</p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:14px;line-height:1.5;">If you did not expect this email, you can ignore it. Never share this link with anyone.</p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:24px 0 0;font-size:14px;line-height:1.5;">Aviation Club</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
