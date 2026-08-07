<x-mail.layout
    :title="__('Backup completed for :site', ['site' => $site->name])"
    :preheader="__('The :type backup finished and is stored.', ['type' => strtolower($session->type)])"
    :message="$message ?? null"
    state-color="#00a32a">

    <x-mail.heading>{{ __('The backup for :site completed', ['site' => $site->name]) }}</x-mail.heading>

    <p>{{ __('There is a fresh restore point for this site. Nothing is needed from you — this is a record, not a request.') }}</p>

    <x-mail.details>
        <x-mail.row :label="__('Site')">{{ $site->name }}</x-mail.row>
        <x-mail.row :label="__('URL')">{{ $site->url }}</x-mail.row>
        <x-mail.row :label="__('Backup type')">{{ ucfirst($session->type) }}</x-mail.row>
        <x-mail.row :label="__('Resource profile')">{{ str_replace('_', ' ', $session->resource_profile) }}</x-mail.row>
        <x-mail.row :label="__('Session')">#{{ $session->id }}</x-mail.row>
        @if($session->completed_at)
            <x-mail.row :label="__('Completed at')">{{ $session->completed_at->format('M j, Y g:ia') }}</x-mail.row>
        @endif
        @if($session->verified_at)
            <x-mail.row :label="__('Verified at')" tone="success">{{ $session->verified_at->format('M j, Y g:ia') }}</x-mail.row>
        @endif
    </x-mail.details>

    <x-mail.button :href="route('sites.backups', $site)">{{ __('Open backups') }}</x-mail.button>
</x-mail.layout>
