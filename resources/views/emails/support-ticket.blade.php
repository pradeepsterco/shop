<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #333;">
    <div style="margin-bottom: 20px;">
        <x-brand.logo area="email" isEmail="true" />
    </div>

    <p>Hi {{ $name }},</p>

    @switch($event)
        @case('received')
            <h2>We have your request</h2>
            <p>Thanks for writing to us. Your request <strong>{{ $ticketNumber }}</strong> ("{{ $subjectLine }}") has reached our support team, and we will reply as soon as we can.</p>
            @break

        @case('staff_reply')
            <h2>New reply on your request</h2>
            <p>Our support team has replied to your request <strong>{{ $ticketNumber }}</strong> ("{{ $subjectLine }}").</p>
            @break

        @case('resolved')
            <h2>Your request is resolved</h2>
            <p>We have marked your request <strong>{{ $ticketNumber }}</strong> ("{{ $subjectLine }}") as resolved.</p>
            <p>If something is still not right, open the request and choose <strong>"No, I still need help"</strong> within {{ $autoCloseDays }} days. Otherwise it closes by itself.</p>
            @break

        @case('seller_action')
            <h2>Support needs your reply</h2>
            <p>Our support team needs your help with {{ $orderNumber ? 'order '.$orderNumber : 'a request' }} (<strong>{{ $ticketNumber }}</strong>, "{{ $subjectLine }}").</p>
            @if ($due)<p>Please reply from your seller panel by <strong>{{ $due }}</strong>.</p>@endif
            @break

        @case('seller_reminder')
            <h2>Support is waiting for your reply</h2>
            <p>We still need your reply on <strong>{{ $ticketNumber }}</strong> ("{{ $subjectLine }}").</p>
            @if ($due)<p>Please reply by <strong>{{ $due }}</strong>.</p>@endif
            @break
    @endswitch

    <p>
        <a href="{{ $url }}" style="display: inline-block; background: #f59e0b; color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: bold;">View your request</a>
    </p>
</body>
</html>
