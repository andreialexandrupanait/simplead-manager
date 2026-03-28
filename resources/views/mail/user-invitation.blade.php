<x-mail::message>
# You've been invited

**{{ $inviterName }}** has invited you to join **{{ config('app.name') }}** as a **{{ $role }}**.

Click the button below to create your account and get started.

<x-mail::button :url="$acceptUrl">
Accept Invitation
</x-mail::button>

This invitation expires on **{{ $expiresAt }}**.

If you didn't expect this invitation, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
