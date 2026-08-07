@php
    $period = $report->period_start->format('d.m.Y').' — '.$report->period_end->format('d.m.Y');
@endphp

<x-mail.layout
    :title="__('Maintenance report for :site', ['site' => $site->name])"
    :preheader="__('Covering :period.', ['period' => $period])"
    :message="$message ?? null">

    <x-mail.heading>{{ __('Maintenance report for :site', ['site' => $site->name]) }}</x-mail.heading>

    @if($schedule?->email_body)
        <p>{!! nl2br(e($schedule->email_body)) !!}</p>
    @else
        <p>{{ __('The maintenance report for :site is ready. It covers everything we did on the site during this period.', ['site' => $site->name]) }}</p>
    @endif

    <x-mail.details>
        <x-mail.row :label="__('Site')">{{ $site->name }}</x-mail.row>
        <x-mail.row :label="__('Period')">{{ $period }}</x-mail.row>
        @if($report->reportTemplate)
            <x-mail.row :label="__('Template')">{{ $report->reportTemplate->name }}</x-mail.row>
        @endif
        @if($report->file_size)
            <x-mail.row :label="__('Size')">{{ $report->file_size_formatted }}</x-mail.row>
        @endif
    </x-mail.details>

    @if($pdfAttached)
        <p>{{ __('The PDF is attached to this email.') }}</p>
    @else
        {{-- Said plainly rather than hidden: the reader needs to know why there
             is no attachment before they go looking for one. --}}
        <p>{{ __('The PDF could not be attached to this email. Use the link below to download it or read it online.') }}</p>
    @endif

    <x-mail.button :href="$downloadUrl">{{ __('Download the report') }}</x-mail.button>

    @if($viewUrl)
        <p><x-mail.link :href="$viewUrl">{{ __('Or read it online') }}</x-mail.link></p>
    @endif

    <x-slot:footer>
        {{ __('The download link works for 7 days. After that, ask us and we will send it again.') }}
    </x-slot:footer>
</x-mail.layout>
