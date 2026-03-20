<div>
    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    <div class="mt-12">
        <x-ui.empty-state
            title="Coming Soon"
            description="This feature is currently under development and will be available in a future update."
            icon="clock"
        />
    </div>
</div>
