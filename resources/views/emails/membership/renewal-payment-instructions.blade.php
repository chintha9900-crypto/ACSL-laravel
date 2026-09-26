<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Renew your Aviation Club International membership</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 20px;font-size:20px;color:#0b2a4a;">Aviation Club International</h1>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hello {{ $name }},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">It's time to renew your membership (number <strong>{{ $number }}</strong>) for another {{ $months }} months.</p>
<p style="margin:0 0 16px;padding:12px 16px;background:#f4f5f7;border-radius:6px;font-size:15px;line-height:1.5;">Amount due: <strong>{{ $currency }} {{ $amount }}</strong></p>
<p style="margin:0 0 8px;font-size:15px;line-height:1.5;">Please pay by bank transfer to:</p>
<p style="margin:0 0 16px;padding:12px 16px;background:#f4f5f7;border-radius:6px;font-size:14px;line-height:1.6;">
{{ $bank->bank_name }}<br>
Account name: {{ $bank->account_name }}<br>
Account number: {{ $bank->account_number }}<br>
@if ($bank->branch)Branch: {{ $bank->branch }}<br>@endif
@if ($bank->sort_code)Sort code: {{ $bank->sort_code }}<br>@endif
@if ($bank->iban)IBAN: {{ $bank->iban }}<br>@endif
@if ($bank->swift_bic)SWIFT/BIC: {{ $bank->swift_bic }}<br>@endif
@if ($bank->instructions)<br>{{ $bank->instructions }}@endif
</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Once you have paid, submit your payment reference and evidence on your membership page. There is no automatic renewal — your membership will not renew unless you pay and an admin confirms it.</p>
<p style="margin:24px 0;text-align:center;"><a href="{{ $url }}" style="display:inline-block;background:#0b2a4a;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:12px 24px;border-radius:6px;">Go to your membership</a></p>
<p style="margin:24px 0 0;font-size:14px;line-height:1.5;">Aviation Club International</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
