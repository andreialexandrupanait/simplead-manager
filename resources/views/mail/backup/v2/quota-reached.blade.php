<x-mail.layout
    :title="__('Storage limit reached on :destination', ['destination' => $destination->name])"
    :preheader="__(':used used of the configured quota.', ['used' => \App\Helpers\FormatHelper::bytes((int) $destination->used_bytes)])"
    :message="$message ?? null"
    state-color="#cc7a00">

    <x-mail.heading>{{ __(':destination has reached its storage limit', ['destination' => $destination->name]) }}</x-mail.heading>

    <p>{{ __('New backups to this destination may be refused until space is freed or the quota is raised. Existing backups are untouched.') }}</p>

    <x-mail.details>
        <x-mail.row :label="__('Destination')">{{ $destination->name }}</x-mail.row>
        <x-mail.row :label="__('Type')">{{ ucfirst($destination->type) }}</x-mail.row>
        <x-mail.row :label="__('Used')" tone="warning">{{ \App\Helpers\FormatHelper::bytes((int) $destination->used_bytes) }}</x-mail.row>
        @if($destination->quota_bytes)
            <x-mail.row :label="__('Quota')">{{ \App\Helpers\FormatHelper::bytes((int) $destination->quota_bytes) }}</x-mail.row>
            <x-mail.row :label="__('Usage')" tone="warning">{{ $destination->usage_percent }}%</x-mail.row>
        @endif
    </x-mail.details>

    <p>{{ __('Retention settings decide how much of this clears on its own. If nothing is due to expire soon, raising the quota is the faster fix.') }}</p>
</x-mail.layout>
