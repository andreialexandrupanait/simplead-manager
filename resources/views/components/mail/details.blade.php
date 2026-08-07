{{-- A label/value block. Prose is right for an uptime alert, which tells a
     story; it is wrong for a backup or a quota, where the reader wants the
     numbers side by side and scans rather than reads. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
    {{ $slot }}
</table>
