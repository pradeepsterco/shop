<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #333;">
    <div style="margin-bottom: 20px;">
        <x-brand.logo area="email" isEmail="true" />
    </div>
    <h2>Congratulations! Your seller application is approved.</h2>
    <p>Dear {{ $sellerProfile->user->name }},</p>
    <p>We are pleased to inform you that your application for store <strong>{{ $sellerProfile->store_name }}</strong> has been approved.</p>
    <p>You can now access your seller dashboard, manage your store profile, and start selling:</p>
    <p><a href="{{ route('seller.dashboard') }}" style="display: inline-block; padding: 10px 20px; background-color: #18181b; color: #ffffff; text-decoration: none; border-radius: 6px;">Go to Seller Dashboard</a></p>
    <p>Thank you for partnering with us!</p>
</body>
</html>

