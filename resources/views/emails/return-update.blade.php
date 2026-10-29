<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #333;">
    <div style="margin-bottom: 20px;">
        <x-brand.logo area="email" isEmail="true" />
    </div>

    @switch($event)
        @case('received')
            <h2>A return has reached you</h2>
            <p>The return for order <strong>{{ $orderNumber }}</strong> ({{ $reason }}) was delivered to you.</p>
            <p>Please check the item and submit its condition (notes and photos) from the order page by <strong>{{ $deadline }}</strong>.</p>
            @break

        @case('qc_passed')
            <h2>Return check passed</h2>
            @if ($forSeller)
                <p>The return for order <strong>{{ $orderNumber }}</strong> passed the quality check. The item has been restocked.</p>
            @else
                <p>Your return for order <strong>{{ $orderNumber }}</strong> passed our check.</p>
                <p>{{ $outcome === 'replacement' ? 'Your replacement will be shipped shortly.' : 'Your refund has been created; we will pay it to your UPI or bank account.' }}</p>
            @endif
            @break

        @case('qc_failed')
            <h2>Return check did not pass</h2>
            <p>The return for order <strong>{{ $orderNumber }}</strong> did not pass the quality check.</p>
            @if ($notes)<p>Notes: {{ $notes }}</p>@endif
            <p>
                @switch($outcome)
                    @case('ship_back') The item is being sent back {{ $forSeller ? 'to the customer at your cost' : 'to you' }}. @break
                    @case('partial_refund') A partial refund has been created. @break
                    @default A full refund has been created.
                @endswitch
            </p>
            @break

        @case('replacement_shipped')
            <h2>Your replacement is on its way</h2>
            <p>The replacement for order <strong>{{ $orderNumber }}</strong> has been shipped.</p>
            @break

        @case('converted_to_refund')
            <h2>Your replacement became a refund</h2>
            <p>The item you returned from order <strong>{{ $orderNumber }}</strong> is no longer in stock, so instead of a replacement we have created a refund for you.</p>
            @break

        @case('lost_refund')
            <h2>Your return was lost by the courier</h2>
            <p>The courier lost the return for order <strong>{{ $orderNumber }}</strong> after picking it up. You will be refunded in full.</p>
            @break
    @endswitch
</body>
</html>
