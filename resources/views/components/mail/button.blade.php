@props(['href'])

@php
    $accent = app(\App\Services\Branding\EmailBrandingService::class)->accentColor();
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
    <tr>
        <td align="center" bgcolor="{{ $accent }}" style="border-radius:8px;">
            <a href="{{ $href }}" style="display:inline-block; padding:12px 26px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">{{ $slot }}</a>
        </td>
    </tr>
</table>
