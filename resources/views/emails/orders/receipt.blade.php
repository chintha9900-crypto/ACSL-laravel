<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your Aviation Club International order receipt — {{ $orderNumber }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 20px;font-size:20px;color:#0b2a4a;">Aviation Club International</h1>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hello {{ $customerName }},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Thank you for your order — your payment has been confirmed. Here is your receipt.</p>

<p style="margin:0 0 16px;padding:12px 16px;background:#f4f5f7;border-radius:6px;font-size:15px;line-height:1.5;">
Order number: <strong>{{ $orderNumber }}</strong><br>
Order date: {{ $orderDate }}<br>
Payment status: <strong>Paid</strong>
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin:0 0 16px;">
<tr style="border-bottom:1px solid #e5e7eb;text-align:left;color:#6b7280;">
<th style="padding:6px 4px;">Product</th>
<th style="padding:6px 4px;">SKU</th>
<th style="padding:6px 4px;">Qty</th>
<th style="padding:6px 4px;text-align:right;">Unit price</th>
<th style="padding:6px 4px;text-align:right;">Line total</th>
</tr>
@foreach ($items as $item)
<tr style="border-bottom:1px solid #f1f2f4;">
<td style="padding:6px 4px;">{{ $item->product_name }}</td>
<td style="padding:6px 4px;">{{ $item->sku }}</td>
<td style="padding:6px 4px;">{{ $item->quantity }}</td>
<td style="padding:6px 4px;text-align:right;">{{ $currency }} {{ number_format((float) $item->unit_price, 2) }}</td>
<td style="padding:6px 4px;text-align:right;">{{ $currency }} {{ number_format((float) $item->line_total, 2) }}</td>
</tr>
@endforeach
<tr>
<td colspan="4" style="padding:10px 4px 0;text-align:right;font-weight:bold;">Order total</td>
<td style="padding:10px 4px 0;text-align:right;font-weight:bold;">{{ $currency }} {{ number_format((float) $totalAmount, 2) }}</td>
</tr>
</table>

<p style="margin:24px 0 0;font-size:14px;line-height:1.5;">Aviation Club International</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
