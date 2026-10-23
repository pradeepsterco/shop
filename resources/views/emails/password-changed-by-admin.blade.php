<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #333;">
    <h2>Security Alert: Password Changed</h2>
    <p>Hello {{ $name }},</p>
    <p>Your password for <strong>{{ config('app.name', 'Khilauna') }}</strong> ({{ $email }}) was recently changed by an administrator.</p>
    <p>For your security, any other active sessions have been logged out.</p>
    <p style="color: #b91c1c; font-weight: bold;">If you did not request or expect this change, please contact our support team immediately.</p>
</body>
</html>

