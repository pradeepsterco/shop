<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; line-height: 1.5; color: #333;">
    <h2>Welcome to {{ config('app.name', 'Khilauna') }} Admin Team</h2>
    <p>Hello {{ $admin->name }},</p>
    <p>You have been invited to join the administrative team for <strong>{{ config('app.name', 'Khilauna') }}</strong>.</p>
    <p>Please click the button below to set your password and activate your access:</p>
    <p>
        <a href="{{ $setUrl }}" style="display: inline-block; padding: 10px 20px; background-color: #18181b; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold;">
            Set Password & Access Account
        </a>
    </p>
    <p style="font-size: 12px; color: #71717a;">This invitation link will expire in 24 hours.</p>
    <p style="font-size: 12px; color: #71717a;">If you did not expect this invitation, you can safely ignore this email.</p>
</body>
</html>

