<x-layouts.app :title="__('Settings')">
    <x-ui.page-header :title="__('Reports')"
                      :subtitle="__('The templates maintenance reports are generated from.')" />

    <x-settings.nav />

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    <livewire:settings.report-templates-settings />
</x-layouts.app>
