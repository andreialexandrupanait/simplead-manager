<x-layouts.app :title="__('Settings')">
    <x-ui.page-header :title="__('Account')"
                      :subtitle="__('Your profile, password, two-factor authentication and API tokens.')" />

    {{-- Order matches the order the sections actually render in: profile-settings
         supplies the first four, two-factor-authentication the last. --}}
    <x-settings.nav :sections="[
        'profile' => __('Profile'),
        'password' => __('Password'),
        'tokens' => __('API tokens'),
        'data' => __('My data'),
        'two-factor' => __('Two-factor'),
    ]" />

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    <div class="space-y-6">
        <livewire:settings.profile-settings />

        {{-- Two-factor was a tab holding one card. --}}
        <livewire:settings.two-factor-authentication />
    </div>
</x-layouts.app>
