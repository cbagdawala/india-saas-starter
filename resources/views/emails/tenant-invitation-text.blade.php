You have been invited

Someone with the account name "{{ $inviterName }}" invited this email address to join a workspace on {{ config('app.name') }}.
Workspace name (chosen by the sender): {{ $tenant->name }}

Review the invitation: {{ $acceptUrl }}

Only accept if you know the person and expected this. The invitation works for the signed-in account that uses this email address and expires in {{ $expiresInDays }} days. If you were not expecting it, ignore this email.
