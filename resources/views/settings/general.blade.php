<x-layouts.app :title="__('Settings')">
    <x-ui.page-header :title="__('General')"
                      :subtitle="__('Application identity, regional settings, monitoring and site statuses.')" />

    <x-settings.nav :sections="[
        'branding' => __('Branding'),
        'regional' => __('Regional'),
        'monitoring' => __('Monitoring'),
        'statuses' => __('Statuses'),
        'connector' => __('WordPress connector'),
        'danger' => __('Danger zone'),
    ]" />

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    <div class="space-y-6">
        <livewire:settings.general-settings />

        {{-- The connector push had a tab of its own ("WordPress") holding one
             button. It is a fleet-wide maintenance action, so it sits with the
             rest of the app-wide settings. --}}
        @livewire(\App\Livewire\Settings\WordPressSettings::class)
    </div>
</x-layouts.app>
