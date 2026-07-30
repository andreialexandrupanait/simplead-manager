<x-layouts.app :title="__('General settings')">
    @include('livewire.settings.partials.settings-tabs')

    <div class="space-y-6">
        <livewire:settings.general-settings />

        {{-- The connector push had a tab of its own ("WordPress") holding one
             button. It is a fleet-wide maintenance action, so it sits with the
             rest of the app-wide settings. --}}
        @livewire(\App\Livewire\Settings\WordPressSettings::class)
    </div>
</x-layouts.app>
