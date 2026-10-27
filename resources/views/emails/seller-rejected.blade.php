<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #333;">
    <div style="margin-bottom: 20px;">
        <x-brand.logo area="email" isEmail="true" />
    </div>
    <h2>Update on your seller application</h2>
    <p>Dear {{ $sellerProfile->user->name }},</p>
    <p>Thank you for your interest in becoming a seller with <strong>{{ $sellerProfile->store_name }}</strong>.</p>
    <p>After reviewing your submission, our team could not approve your application at this time due to the following reason:</p>
    <blockquote style="padding: 10px 15px; background: #f4f4f5; border-left: 4px solid #ef4444; margin: 15px 0;">
        {{ $reason }}
    </blockquote>
    <p>You can review your submitted details, make the required corrections or upload fresh documents, and resubmit your application:</p>
    <p><a href="{{ route('seller.apply') }}" style="display: inline-block; padding: 10px 20px; background-color: #18181b; color: #ffffff; text-decoration: none; border-radius: 6px;">Update & Resubmit Application</a></p>
</body>
</html>

