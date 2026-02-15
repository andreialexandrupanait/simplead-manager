<div class="mb-6 border-b border-gray-200">
    <nav class="-mb-px flex gap-6">
        @php
            $tabs = [
                ['route' => 'settings.general', 'label' => __('General')],
                ['route' => 'settings.notifications', 'label' => __('Notifications')],
                ['route' => 'settings.integrations', 'label' => __('Integrations')],
                ['route' => 'settings.site-presets', 'label' => __('Site Presets')],
                ['route' => 'settings.status-pages', 'label' => __('Status Pages')],
                ['route' => 'settings.report-templates', 'label' => __('Report Templates')],
                ['route' => 'settings.application-backup', 'label' => __('Application Backup')],
                ['route' => 'settings.profile', 'label' => __('Profile')],
            ];
        @endphp

        @foreach($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
               class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition
                      {{ request()->routeIs($tab['route'])
                          ? 'border-purple-500 text-purple-600'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
