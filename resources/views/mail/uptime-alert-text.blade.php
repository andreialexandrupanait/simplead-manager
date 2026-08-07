@if($isDown){{ __('DOWN alert: :url appears down', ['url' => $siteUrl]) }}@else{{ __('UP alert: :url is back online', ['url' => $siteUrl]) }}@endif


{{ $greetingName ? __('Hi :name,', ['name' => $greetingName]) : __('Hi there,') }}

@if($isDown)
{{ __('Our systems have detected that your site — :site — went down on :moment and :cause.', ['site' => $siteUrl, 'moment' => $momentLabel.' ('.$timezoneLabel.')', 'cause' => $causeClause]) }}
@if($confirmation)

{{ $confirmation }}
@endif

{{ __('We recommend that you visit your site to confirm the problem.') }}
@if($remedies)

{{ __('What to check first') }}:
@foreach($remedies as $remedy)
- {{ $remedy }}
@endforeach
@endif

{{ __('If your site is up and running smoothly again, just ignore this email.') }}

{{ __('You can see what went wrong, adjust your uptime settings, or pause monitoring altogether here:') }}
{{ $uptimeUrl }}
@else
{{ __('Good news! Our systems have detected that your site — :site — was down for :duration but is back up and running, as of :moment.', ['site' => $siteUrl, 'duration' => $duration, 'moment' => $momentLabel.' ('.$timezoneLabel.')']) }}

{{ __('You can see what went wrong, adjust your uptime settings, or pause monitoring altogether here:') }}
{{ $uptimeUrl }}

{{ __('If you fixed the issue, nice work!') }}

{{ __('If this keeps happening and you are not sure why your site goes down, it is worth asking your hosting provider to help you diagnose it.') }}
@endif

--
@if($recipientAddress){{ __(':address is set up to receive uptime notifications for :site.', ['address' => $recipientAddress, 'site' => $siteUrl]) }}@else{{ __('You are receiving uptime notifications for :site.', ['site' => $siteUrl]) }}@endif

{{ __('Cheers, the :app team', ['app' => $appName]) }}
