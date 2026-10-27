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
            <p style="margin: 0 0 8px 0; font-size: 13px; font-weight: bold;">Order Summary</p>
            <p style="margin: 0 0 4px 0; font-size: 13px; color: #52525b;">Placed on: <strong>@datetime($order->placed_at)</strong></p>
            <p style="margin: 0; font-size: 13px; color: #52525b;">Total Amount: <strong>₹{{ number_format($order->grand_total / 100) }}</strong></p>
            <p style="margin: 4px 0 0 0; font-size: 13px; color: #52525b;">Payment: Cash on Delivery</p>
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

