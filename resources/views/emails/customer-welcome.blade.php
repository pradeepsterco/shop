<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #333;">
    <div style="margin-bottom: 20px;">
        <x-brand.logo area="email" isEmail="true" />
    </div>
    <h2>Welcome to {{ brand_name() }}!</h2>
    <p>Hello {{ $user->name }},</p>
    <p>An account has been created for you at <strong>{{ brand_name() }}</strong> by an administrator.</p>
    <p>Please click the button below to set your password and access your account:</p>
    <p>
        <a href="{{ $setUrl }}" style="display: inline-block; padding: 10px 20px; background-color: #18181b; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold;">
            Set Your Password
        </a>
    </p>
    <p style="font-size: 12px; color: #71717a;">This link will expire in 24 hours.</p>
    <p style="font-size: 12px; color: #71717a;">If you did not expect this email, please contact our support team.</p>
</body>
</html>

