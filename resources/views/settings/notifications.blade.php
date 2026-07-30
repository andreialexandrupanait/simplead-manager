<x-layouts.app :title="__('Notification settings')">
    @include('livewire.settings.partials.settings-tabs')

    <div class="space-y-6">
        <livewire:settings.notification-settings />

        {{-- SMTP was its own tab called "Email", which is where you looked for
             notification settings and did not find them. Mail is a delivery
             channel; it belongs with the other channels. --}}
        <livewire:settings.email-settings />
    </div>
</x-layouts.app>
