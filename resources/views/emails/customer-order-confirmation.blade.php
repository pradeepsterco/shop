<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Order Confirmation</title>
</head>
<body style="font-family: sans-serif; background-color: #f4f4f5; margin: 0; padding: 24px; color: #18181b;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #e4e4e7;">
        <div style="margin-bottom: 24px;">
            <x-brand.logo area="email" isEmail="true" />
        </div>
        <h1 style="font-size: 20px; font-weight: bold; margin-top: 0; color: #18181b;">
            Thank you for your order!
        </h1>
        <p style="font-size: 14px; color: #52525b; line-height: 1.5;">
            Your order <strong>#{{ $order->order_number }}</strong> has been confirmed and will be processed for fulfillment.
        </p>

        <div style="background: #fafafa; border: 1px solid #e4e4e7; border-radius: 8px; padding: 16px; margin: 24px 0;">
            <p style="margin: 0 0 12px 0; font-size: 13px; font-weight: bold; border-bottom: 1px solid #e4e4e7; padding-bottom: 6px;">Order Summary</p>
            <p style="margin: 0 0 4px 0; font-size: 13px; color: #52525b;">Placed on: <strong>@datetime($order->placed_at)</strong></p>
            <div style="margin: 12px 0; font-size: 13px; line-height: 1.6; color: #52525b;">
                <div style="display: flex; justify-content: space-between;">
                    <span>Items Total (MRP):</span>
                    <span style="float: right;">₹{{ number_format($order->items_mrp_total / 100) }}</span>
                </div>
                @if (($order->discount_total ?? 0) > 0)
                    <div style="display: flex; justify-content: space-between; color: #16a34a;">
                        <span>Catalog Savings:</span>
                        <span style="float: right;">−₹{{ number_format($order->discount_total / 100) }}</span>
                    </div>
                @endif
                @if (($order->coupon_discount_total ?? 0) > 0)
                    <div style="display: flex; justify-content: space-between; color: #16a34a; font-weight: bold;">
                        <span>Coupon ({{ $order->coupon_code }}):</span>
                        <span style="float: right;">−₹{{ number_format($order->coupon_discount_total / 100) }}</span>
                    </div>
                @endif
                <div style="display: flex; justify-content: space-between;">
                    <span>Shipping Charges:</span>
                    <span style="float: right; color: #16a34a;">{{ $order->delivery_fee === 0 ? 'Free' : '₹'.number_format($order->delivery_fee / 100) }}</span>
                </div>
            </div>
            <div style="border-top: 1px dashed #d4d4d8; padding-top: 8px; font-size: 14px; font-weight: bold; color: #18181b;">
                <span>You Pay:</span>
                <span style="float: right;">₹{{ number_format($order->grand_total / 100) }}</span>
            </div>
            <p style="margin: 10px 0 0 0; font-size: 12px; color: #71717a;">Payment Method: Cash on Delivery</p>
        </div>

        <a href="{{ $viewUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: bold;">
            Track Your Order
        </a>

        <p style="margin-top: 32px; font-size: 12px; color: #a1a1aa; border-top: 1px solid #f4f4f5; padding-top: 16px;">
            If you have any questions, please contact our support team.
        </p>
    </div>
</body>
</html>

