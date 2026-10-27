<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Order Received</title>
</head>
<body style="font-family: sans-serif; background-color: #f4f4f5; margin: 0; padding: 24px; color: #18181b;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #e4e4e7;">
        <div style="margin-bottom: 24px;">
            <x-brand.logo area="email" isEmail="true" />
        </div>
        <h1 style="font-size: 20px; font-weight: bold; margin-top: 0; color: #18181b;">
            New Order Received!
        </h1>
        <p style="font-size: 14px; color: #52525b; line-height: 1.5;">
            You have received a new package for order <strong>#{{ $sellerOrder->order->order_number }}</strong> placed on <strong>@datetime($sellerOrder->placed_at)</strong>.
        </p>

        <div style="background: #fafafa; border: 1px solid #e4e4e7; border-radius: 8px; padding: 16px; margin: 24px 0;">
            <p style="margin: 0 0 12px 0; font-size: 13px; font-weight: bold;">Package Details</p>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 12px;">
                <thead>
                    <tr style="border-bottom: 1px solid #e4e4e7; text-align: left; color: #71717a;">
                        <th style="padding-bottom: 6px;">Item</th>
                        <th style="padding-bottom: 6px; text-align: center;">Qty</th>
                        <th style="padding-bottom: 6px; text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sellerOrder->items as $item)
                        <tr style="border-bottom: 1px solid #f4f4f5;">
                            <td style="padding: 8px 0; color: #18181b;">
                                <strong>{{ $item->title }}</strong>
                                @if ($item->variant_title)
                                    <div style="font-size: 11px; color: #71717a;">{{ $item->variant_title }}</div>
                                @endif
                                <div style="font-size: 11px; color: #a1a1aa;">SKU: {{ $item->sku }}</div>
                            </td>
                            <td style="padding: 8px 0; text-align: center; color: #52525b;">
                                {{ $item->qty }}
                            </td>
                            <td style="padding: 8px 0; text-align: right; color: #18181b; font-weight: bold;">
                                ₹{{ number_format($item->line_total / 100) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p style="margin: 0; font-size: 13px; color: #52525b;">Package Order Value: <strong>₹{{ number_format($sellerOrder->grand_total / 100) }}</strong></p>
            <p style="margin: 4px 0 0 0; font-size: 13px; color: #52525b;">Items Count: {{ $sellerOrder->items->count() }} ({{ $sellerOrder->items->sum('qty') }} units)</p>
        </div>

        <a href="{{ $viewUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: bold;">
            View in Seller Portal
        </a>

        <p style="margin-top: 32px; font-size: 12px; color: #a1a1aa; border-top: 1px solid #f4f4f5; padding-top: 16px;">
            Please confirm and pack this package promptly to meet dispatch SLAs.
        </p>
    </div>
</body>
</html>

