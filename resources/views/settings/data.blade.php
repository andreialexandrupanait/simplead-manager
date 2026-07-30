<x-layouts.app :title="__('Settings')">
    <x-ui.page-header :title="__('Backup & Data')"
                      :subtitle="__('Application backup, where it is written, and how long data is kept.')" />

    <x-settings.nav :sections="[
        'backup-status' => __('Status'),
        'backup-config' => __('Schedule'),
        'backup-history' => __('History'),
        'destinations' => __('Storage destinations'),
        'retention' => __('Retention'),
    ]" />

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    <div class="space-y-6">
        <livewire:settings.application-backup />

        {{-- Destinations were authored on Integrations and chosen here — two
             screens for one decision. --}}
        <livewire:settings.storage-destinations />

        {{-- How long data is kept is the other half of "what happens to our
             data"; it was a separate tab. --}}
        <livewire:settings.data-retention-settings />
    </div>
</x-layouts.app>
