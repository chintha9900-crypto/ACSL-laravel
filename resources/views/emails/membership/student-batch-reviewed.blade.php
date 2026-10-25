<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Aviation Club</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 20px;font-size:20px;color:#0b2a4a;">Aviation Club</h1>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hello {{ $name }},</p>
@if ($status === 'approved')
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">The student list you submitted on {{ $submittedOn }} has been approved. {{ $count }} student {{ $count === 1 ? 'member has' : 'members have' }} been added to your School Club.</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Each student will receive their own email with a secure, one-time link to create their password. Their membership is free — there are no joining or renewal fees.</p>
@elseif ($status === 'more_details_required')
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">We need more information about the student list you submitted on {{ $submittedOn }}:</p>
<p style="margin:0 0 16px;padding:12px 16px;background:#f4f5f7;border-radius:6px;font-size:15px;line-height:1.5;white-space:pre-line;">{{ $requestMessage }}</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Please sign in and resubmit the corrected student list from Manage Student Members.</p>
@else
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">The student list you submitted on {{ $submittedOn }} was not approved. No student accounts were created. If you have any questions, please contact Aviation Club.</p>
@endif
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;"><a href="{{ $url }}" style="color:#0b2a4a;word-break:break-all;">{{ $url }}</a></p>
<p style="margin:24px 0 0;font-size:14px;line-height:1.5;">Aviation Club</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
