<div>
    @php
        $comingSoonTitle = match(Route::currentRouteName()) {
            'sites.security.admin-ux' => 'Admin UX',
            'sites.security.content-media' => 'Content & Media',
            'sites.security.email' => 'Email',
            default => 'Coming Soon',
        };
    @endphp

    <x-ui.page-header :title="$comingSoonTitle" subtitle="Coming soon" />

    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    <div class="mt-12">
        <x-ui.empty-state
            title="Coming Soon"
            description="This feature is currently under development and will be available in a future update."
            icon="clock"
        />
    </div>
</div>
