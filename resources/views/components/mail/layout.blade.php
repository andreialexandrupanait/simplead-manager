@props([
    'title' => '',
    'preheader' => '',
    'message' => null,
    'footer' => null,
    'stateColor' => null,
])

@php
    $branding = app(\App\Services\Branding\EmailBrandingService::class);
    $appName = $branding->appName();
    $accent = $branding->accentColor();
    $logoPath = $branding->logoPath();
@endphp

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="color-scheme" content="light" />
    <meta name="supported-color-schemes" content="light" />
    <title>{{ $title }}</title>
    <style type="text/css">
        /* Clients that keep <style> get the niceties; everything load-bearing is
           also inlined below, because Outlook and Gmail's clipped view are not. */
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        table { border-collapse: collapse; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a { color: {{ $accent }}; }
        .sam-body p { margin: 0 0 16px; }
        .sam-body p:last-child { margin-bottom: 0; }
        @media only screen and (max-width: 620px) {
            .sam-wrap { width: 100% !important; }
            .sam-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .sam-h1 { font-size: 22px !important; line-height: 30px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f1f3f6;">

    {{-- Preheader: the grey line the inbox shows next to the subject. Kept out of
         sight, and padded so the client does not pull body text in after it. --}}
    <div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">
        {{ $preheader }}
        &#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;
    </div>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f1f3f6;">
        <tr>
            <td align="center" style="padding:24px 12px;">

                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="sam-wrap" style="width:600px; max-width:600px;">

                    {{-- State rule: what the dark band cannot say on its own.
                         Red or green across the top, read before any text is. --}}
                    @if($stateColor)
                        <tr>
                            <td style="height:4px; line-height:4px; font-size:0; background-color:{{ $stateColor }}; border-radius:12px 12px 0 0;">&nbsp;</td>
                        </tr>
                    @endif

                    {{-- Brand band. The gradient is an enhancement — Outlook drops
                         background-image and keeps the solid colour underneath,
                         which is why both are set. The mark is embedded in its
                         white-on-transparent form; see EmailBrandingService. --}}
                    <tr>
                        <td class="sam-pad" align="center" style="background-color:#1f2340; background-image:linear-gradient(115deg, #4c2a6b 0%, #1f2340 55%, #10131f 100%); padding:26px 32px; @if(! $stateColor) border-radius:12px 12px 0 0; @endif">
                            @if($logoPath && $message)
                                <img src="{{ $message->embed($logoPath) }}" alt="{{ $appName }}" height="30" style="height:30px; width:auto; display:block; margin:0 auto; border:0;" />
                            @else
                                <span style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:19px; font-weight:700; letter-spacing:0.01em; color:#ffffff;">{{ $appName }}</span>
                            @endif
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="sam-pad sam-body" style="background-color:#ffffff; padding:32px; border-radius:0 0 12px 12px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#374151;">
                            {{ $slot }}
                        </td>
                    </tr>
                </table>

                @if($footer)
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="sam-wrap" style="width:600px; max-width:600px;">
                        <tr>
                            <td class="sam-pad" style="padding:20px 32px 8px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:19px; color:#9199a8;">
                                {{ $footer }}
                            </td>
                        </tr>
                    </table>
                @endif

            </td>
        </tr>
    </table>
</body>
</html>
