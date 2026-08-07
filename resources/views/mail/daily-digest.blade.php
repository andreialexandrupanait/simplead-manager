@php
    $allClear = $digest['sites_down'] === 0
        && $digest['incidents_24h'] === 0
        && $digest['backups_failed_24h'] === 0
        && $digest['updates_available'] === 0;
@endphp

<x-mail.layout
    :title="__('Daily digest — :date', ['date' => $digest['date']])"
    :preheader="$allClear
        ? __('All :count sites up, nothing needs attention.', ['count' => $digest['total_sites']])
        : __(':down down, :incidents new incidents, :failed failed backups.', ['down' => $digest['sites_down'], 'incidents' => $digest['incidents_24h'], 'failed' => $digest['backups_failed_24h']])"
    :message="$message ?? null"
    :state-color="$allClear ? '#00a32a' : '#cc7a00'">

    <x-mail.heading>{{ __('Daily digest') }}</x-mail.heading>

    <p>{{ $digest['date'] }}</p>

    @if($allClear)
        <p>{{ __('Everything is where it should be: every site answering, no new incidents, every backup finished, nothing waiting on an update.') }}</p>
    @endif

    <x-mail.section>{{ __('Sites') }}</x-mail.section>
    <x-mail.details>
        <x-mail.row :label="__('Monitored')">{{ $digest['total_sites'] }}</x-mail.row>
        <x-mail.row :label="__('Up')" tone="success">{{ $digest['sites_up'] }}</x-mail.row>
        @if($digest['sites_down'] > 0)
            <x-mail.row :label="__('Down')" tone="danger">{{ $digest['sites_down'] }}</x-mail.row>
        @endif
    </x-mail.details>

    <x-mail.section>{{ __('Incidents, last 24 hours') }}</x-mail.section>
    <x-mail.details>
        <x-mail.row :label="__('Opened')" :tone="$digest['incidents_24h'] > 0 ? 'danger' : null">{{ $digest['incidents_24h'] }}</x-mail.row>
        <x-mail.row :label="__('Resolved')" tone="success">{{ $digest['resolved_24h'] }}</x-mail.row>
    </x-mail.details>

    <x-mail.section>{{ __('Backups, last 24 hours') }}</x-mail.section>
    <x-mail.details>
        <x-mail.row :label="__('Completed')" tone="success">{{ $digest['backups_24h'] }}</x-mail.row>
        @if($digest['backups_failed_24h'] > 0)
            <x-mail.row :label="__('Failed')" tone="danger">{{ $digest['backups_failed_24h'] }}</x-mail.row>
        @endif
    </x-mail.details>

    @if($digest['updates_available'] > 0)
        <x-mail.section>{{ __('Waiting on you') }}</x-mail.section>
        <x-mail.details>
            <x-mail.row :label="__('Sites with updates available')" tone="warning">{{ $digest['updates_available'] }}</x-mail.row>
        </x-mail.details>
    @endif

    <x-mail.button :href="route('dashboard')">{{ __('Open the dashboard') }}</x-mail.button>

    <x-slot:footer>
        {{ __('You receive this digest once a day. It can be turned off per account.') }}
    </x-slot:footer>
</x-mail.layout>
