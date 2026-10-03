Aviation Club International

Hello {!! str_replace(["\r", "\n"], ' ', $customerName) !!},

Thank you for your order — your payment has been confirmed. Here is your receipt.

Order number: {!! $orderNumber !!}
Order date: {!! $orderDate !!}
Payment status: Paid

@foreach ($items as $item)
{!! $item->product_name !!} ({!! $item->sku !!}) x{{ $item->quantity }} — {!! $currency !!} {{ number_format((float) $item->line_total, 2) }}
@endforeach

Order total: {!! $currency !!} {{ number_format((float) $totalAmount, 2) }}

Aviation Club International
