@php
    $accent = app(\App\Services\Branding\EmailBrandingService::class)->accentColor();

    $anchor = static fn (string $href, string $label): string => '<a href="'.e($href).'" style="color:'.e($accent).'; text-decoration:none; font-weight:500;">'.e($label).'</a>';

    $siteLink = $anchor($siteUrl, $siteUrl);
    $hereLink = $anchor($uptimeUrl, __('here'));

    $heading = $isDown
        ? __('DOWN alert: :url appears down', ['url' => $siteLink])
        : __('UP alert: :url is back online', ['url' => $siteLink]);

    $preheader = $isDown
        ? __('Detected on :moment — :cause.', ['moment' => $momentLabel, 'cause' => $causeClause])
        : __('Back up after :duration of downtime.', ['duration' => $duration]);
@endphp

<x-mail.layout
    :title="strip_tags($heading)"
    :preheader="$preheader"
    :message="$message ?? null"
    :state-color="$isDown ? '#d63638' : '#00a32a'">

    <h1 class="sam-h1" style="margin:0 0 20px; font-size:25px; line-height:33px; font-weight:700; color:#111827;">
        {!! $heading !!}
    </h1>

    <p>{{ $greetingName ? __('Hi :name,', ['name' => $greetingName]) : __('Hi there,') }}</p>

    @if($isDown)
        <p>
            {!! __('Our systems have detected that your site — :site — went down on :moment and :cause.', [
                'site' => $siteLink,
                'moment' => e($momentLabel).' ('.e($timezoneLabel).')',
                'cause' => e($causeClause),
            ]) !!}
        </p>

        @if($confirmation)
            <p>{{ $confirmation }}</p>
        @endif

        <p>{{ __('We recommend that you visit your site to confirm the problem.') }}</p>

        @if($remedies)
            {{-- Bordered rather than filled: a tinted panel reads as a warning
                 banner, and the reader has had enough alarm from the subject
                 line. This is the calm part of the email. --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0; border:1px solid #e3e7ee; border-radius:10px;">
                <tr>
                    <td style="padding:18px 20px 6px;">
                        <div style="font-size:12px; font-weight:700; letter-spacing:0.07em; text-transform:uppercase; color:#8b93a3;">{{ __('What to check first') }}</div>
                    </td>
                </tr>
                @foreach($remedies as $remedy)
                    <tr>
                        <td style="padding:0 20px {{ $loop->last ? '18px' : '10px' }};">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td width="18" valign="top" style="width:18px; padding-top:2px; font-size:15px; line-height:22px; color:{{ $accent }};">&bull;</td>
                                    <td valign="top" style="font-size:14px; line-height:22px; color:#4b5563;">{{ $remedy }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif

        <p>{{ __('If your site is up and running smoothly again, just ignore this email.') }}</p>

        <p>
            {!! __('You can see what went wrong, adjust your uptime settings, or pause monitoring altogether :here.', ['here' => $hereLink]) !!}
        </p>
    @else
        <p>
            {!! __('Good news! Our systems have detected that your site — :site — was down for :duration but is back up and running, as of :moment.', [
                'site' => $siteLink,
                'duration' => e($duration),
                'moment' => e($momentLabel).' ('.e($timezoneLabel).')',
            ]) !!}
        </p>

        <p>
            {!! __('You can see what went wrong, adjust your uptime settings, or pause monitoring altogether :here.', ['here' => $hereLink]) !!}
        </p>

        <p>{{ __('If you fixed the issue, nice work!') }}</p>

        <p>{{ __('If this keeps happening and you are not sure why your site goes down, it is worth asking your hosting provider to help you diagnose it.') }}</p>
    @endif

    <x-slot:footer>
        <p style="margin:0 0 6px;">
            @if($recipientAddress)
                {{ __(':address is set up to receive uptime notifications for :site.', ['address' => $recipientAddress, 'site' => $siteUrl]) }}
            @else
                {{ __('You are receiving uptime notifications for :site.', ['site' => $siteUrl]) }}
            @endif
            {!! __('An administrator can change that in :settings.', ['settings' => '<a href="'.e($settingsUrl).'" style="color:#6b7280; text-decoration:underline;">'.e(__('notification settings')).'</a>']) !!}
        </p>
        <p style="margin:0;">{{ __('Cheers, the :app team', ['app' => $appName]) }}</p>
    </x-slot:footer>
</x-mail.layout>
