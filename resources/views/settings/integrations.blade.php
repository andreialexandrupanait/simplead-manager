<x-layouts.app :title="__('Settings')">
    <x-ui.page-header :title="__('Integrations')"
                      :subtitle="__('External services connected to the application.')" />

    <x-settings.nav :sections="[
        'ai' => __('AI providers'),
        'services' => __('Services'),
    ]" />

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    <livewire:settings.integrations-settings />
</x-layouts.app>
