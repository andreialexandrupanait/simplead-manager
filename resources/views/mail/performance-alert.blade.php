@php
    $performanceUrl = route('sites.performance', $site);
@endphp

<x-mail.layout
    :title="__('Performance dropped on :site', ['site' => $site->name])"
    :preheader="__(':device score fell from :previous to :current.', ['device' => ucfirst($device), 'previous' => $previousScore, 'current' => $currentScore])"
    :message="$message ?? null"
    state-color="#cc7a00">

    <x-mail.heading>{{ __('The :device performance score for :site dropped :drop points', ['device' => strtolower($device), 'site' => $site->name, 'drop' => $drop]) }}</x-mail.heading>

    <p>{{ __('A PageSpeed test just came back noticeably slower than the one before it. A single test can be noisy, but a drop this size usually follows a real change — a new plugin, a heavier image, or a third-party script.') }}</p>

    <x-mail.details>
        <x-mail.row :label="__('Site')">{{ $site->name }}</x-mail.row>
        <x-mail.row :label="__('URL')">{{ $site->url }}</x-mail.row>
        <x-mail.row :label="__('Device')">{{ ucfirst($device) }}</x-mail.row>
        <x-mail.row :label="__('Previous score')">{{ $previousScore }}</x-mail.row>
        <x-mail.row :label="__('Current score')" tone="danger">{{ $currentScore }}</x-mail.row>
        <x-mail.row :label="__('Change')" tone="danger">&minus;{{ $drop }}</x-mail.row>
    </x-mail.details>

    <p>{{ __('The performance page shows which metric moved, so you can tell a slower server from a heavier page.') }}</p>

    <x-mail.button :href="$performanceUrl">{{ __('Open performance') }}</x-mail.button>
</x-mail.layout>
