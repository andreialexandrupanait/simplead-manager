<x-layouts.app :title="__('Backup & data')">
    @include('livewire.settings.partials.settings-tabs')

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
