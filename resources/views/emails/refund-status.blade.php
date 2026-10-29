<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #333;">
    <div style="margin-bottom: 20px;">
        <x-brand.logo area="email" isEmail="true" />
    </div>

    @if ($isPaid)
        <h2>Refund paid</h2>
        <p>
            @if ($forAdmin)
                A refund of <strong>₹{{ $amount }}</strong> for order <strong>{{ $orderNumber }}</strong> was marked as paid.
            @else
                We have sent your refund of <strong>₹{{ $amount }}</strong> for order <strong>{{ $orderNumber }}</strong>.
            @endif
        </p>
        <p>Paid to: {{ $destination }}<br>Reference (UTR): <strong>{{ $utr }}</strong></p>
    @else
        <h2>Refund failed</h2>
        <p>
            @if ($forAdmin)
                The refund of <strong>₹{{ $amount }}</strong> for order <strong>{{ $orderNumber }}</strong> was marked as failed.
            @else
                We could not send your refund of <strong>₹{{ $amount }}</strong> for order <strong>{{ $orderNumber }}</strong>.
            @endif
        </p>
        <p>Reason: {{ $reason }}<br>Payout details on file: {{ $destination }}</p>
        @unless ($forAdmin)
            <p>Please update your UPI ID or bank details from your order page and we will retry the refund.</p>
        @endunless
    @endif
</body>
</html>
