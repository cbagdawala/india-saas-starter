<!DOCTYPE html>
<html lang="en">
<body style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #111; max-width: 520px; margin: 0 auto; padding: 24px;">
<h2 style="margin-top: 0;">You have been invited</h2>
<p>Someone with the account name "{{ $inviterName }}" invited this email address to join a workspace on {{ config('app.name') }}. The workspace is called:</p>
<p style="background: #f4f4f5; padding: 10px 14px; border-radius: 6px;">{{ $tenant->name }}</p>
<p><a href="{{ $acceptUrl }}" style="display: inline-block; background: #4f46e5; color: #fff; padding: 10px 18px; border-radius: 6px; text-decoration: none;">Review the invitation</a></p>
<p style="color: #555; font-size: 13px;">The workspace name and the inviter's name are chosen by the sender, not by {{ config('app.name') }}. Only accept if you know the person and expected this. The invitation works for the signed-in account that uses this email address and expires in {{ $expiresInDays }} days. If you were not expecting it, ignore this email.</p>
</body>
</html>
