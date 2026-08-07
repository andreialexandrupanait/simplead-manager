@php
    $backupsUrl = route('sites.backups', $site);
@endphp

<x-mail.layout
    :title="__('Backup failed for :site', ['site' => $site->name])"
    :preheader="__('The :type backup did not finish. :error', ['type' => strtolower($backup->type), 'error' => $errorMessage])"
    :message="$message ?? null"
    state-color="#d63638">

    <x-mail.heading>{{ __('The backup for :site did not finish', ['site' => $site->name]) }}</x-mail.heading>

    <p>{{ __('A backup was running and stopped before it completed, so there is no restore point from this run. The site itself is unaffected.') }}</p>

    <x-mail.details>
        <x-mail.row :label="__('Site')">{{ $site->name }}</x-mail.row>
        <x-mail.row :label="__('URL')">{{ $site->url }}</x-mail.row>
        <x-mail.row :label="__('Backup type')">{{ ucfirst($backup->type) }}</x-mail.row>
        <x-mail.row :label="__('Started by')">{{ ucfirst(str_replace('_', ' ', $backup->trigger)) }}</x-mail.row>
        @if($backup->started_at)
            <x-mail.row :label="__('Started at')">{{ $backup->started_at->format('M j, Y g:ia') }}</x-mail.row>
        @endif
        <x-mail.row :label="__('Error')" tone="danger">{{ $errorMessage }}</x-mail.row>
    </x-mail.details>

    <p>{{ __('Scheduled backups keep running, so the next one may well succeed on its own. If it fails again with the same error, the cause is worth looking into rather than waiting out.') }}</p>

    <x-mail.button :href="$backupsUrl">{{ __('Open backups') }}</x-mail.button>
</x-mail.layout>
