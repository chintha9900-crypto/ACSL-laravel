<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your Aviation Club order receipt — {{ $orderNumber }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;background:#f4f5f7;padding:24px 12px;">
<tr><td align="center" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
<tr><td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;">
<h1 style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 20px;font-size:20px;color:#0b2a4a;">Aviation Club</h1>
@if (! empty($isTest))
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;padding:8px 12px;border:1px solid #CC001F;color:#CC001F;font-size:14px;font-weight:bold;">TEST ORDER — simulated Dummy / Test Payment (Development Only). No money was taken. This is not a valid receipt.</p>
@endif
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:15px;line-height:1.5;">Hello {{ $customerName }},</p>
<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;font-size:15px;line-height:1.5;">Thank you for your order — your payment has been confirmed. Here is your receipt.</p>

<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:0 0 16px;padding:12px 16px;background:#f4f5f7;border-radius:6px;font-size:15px;line-height:1.5;">
Order number: <strong>{{ $orderNumber }}</strong><br>
Order date: {{ $orderDate }}<br>
Payment status: <strong>Paid</strong>
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;font-size:13px;margin:0 0 16px;">
<tr style="border-bottom:1px solid #e5e7eb;text-align:left;color:#6b7280;">
<th style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;">Product</th>
<th style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;">SKU</th>
<th style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;">Qty</th>
<th style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;text-align:right;">Unit price</th>
<th style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;text-align:right;">Line total</th>
</tr>
@foreach ($items as $item)
<tr style="border-bottom:1px solid #f1f2f4;">
<td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;">{{ $item->product_name }}</td>
<td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;">{{ $item->sku }}</td>
<td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;">{{ $item->quantity }}</td>
<td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;text-align:right;">{{ $currency }} {{ number_format((float) $item->unit_price, 2) }}</td>
<td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:6px 4px;text-align:right;">{{ $currency }} {{ number_format((float) $item->line_total, 2) }}</td>
</tr>
@endforeach
@if ($deliveryCharge !== null)
<tr>
<td colspan="4" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:10px 4px 0;text-align:right;">Delivery (Sri Lanka)</td>
<td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:10px 4px 0;text-align:right;">{{ $currency }} {{ $deliveryCharge }}</td>
</tr>
@endif
<tr>
<td colspan="4" style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:10px 4px 0;text-align:right;font-weight:bold;">Order total</td>
<td style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;padding:10px 4px 0;text-align:right;font-weight:bold;">{{ $currency }} {{ number_format((float) $totalAmount, 2) }}</td>
</tr>
</table>

<p style="font-family:Calibri,Carlito,'Segoe UI',Arial,sans-serif;margin:24px 0 0;font-size:14px;line-height:1.5;">Aviation Club</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
