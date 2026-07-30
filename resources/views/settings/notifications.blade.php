<x-layouts.app :title="__('Settings')">
    <x-ui.page-header :title="__('Notifications')"
                      :subtitle="__('Where alerts go, what triggers them, and how they read.')" />

    <x-settings.nav :sections="[
        'channels' => __('Channels'),
        'preferences' => __('Preferences'),
        'quiet-hours' => __('Quiet hours'),
        'escalation' => __('Escalation'),
        'templates' => __('Templates'),
        'smtp' => __('Email delivery'),
    ]" />

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    <div class="space-y-6">
        <livewire:settings.notification-settings />

        {{-- SMTP was its own tab called "Email", which is where you looked for
             notification settings and did not find them. Mail is a delivery
             channel; it belongs with the other channels. --}}
        <livewire:settings.email-settings />
    </div>
</x-layouts.app>
