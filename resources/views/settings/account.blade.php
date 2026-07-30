<x-layouts.app :title="__('Account')">
    @include('livewire.settings.partials.settings-tabs')

    <div class="space-y-6">
        <livewire:settings.profile-settings />

        {{-- Two-factor was a tab holding one card. --}}
        <livewire:settings.two-factor-authentication />
    </div>
</x-layouts.app>
