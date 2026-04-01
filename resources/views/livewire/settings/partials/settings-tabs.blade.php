<div class="sticky top-16 z-10 -mx-6 lg:-mx-8 px-6 lg:px-8 mb-6 border-b border-gray-200 bg-gray-50/95 backdrop-blur">
    <nav class="-mb-px flex gap-6 overflow-x-auto scrollbar-hide">
        @php
            $tabs = [['route' => 'settings.profile', 'label' => __('Profile'), 'group' => false]];

            if (auth()->user()->isAdmin()) {
                $tabs = array_merge([
                    // Platform
                    ['route' => 'settings.general', 'label' => __('General'), 'group' => false],
                    ['route' => 'settings.notifications', 'label' => __('Notifications'), 'group' => false],
                    ['route' => 'settings.email', 'label' => __('Email'), 'group' => false],
                    ['route' => 'settings.integrations', 'label' => __('Integrations'), 'group' => false],
                    // WordPress
                    ['route' => 'settings.wordpress', 'label' => __('WordPress'), 'group' => true],
                    ['route' => 'settings.status-pages', 'label' => __('Status Pages'), 'group' => false],
                    // Content
                    ['route' => 'settings.report-templates', 'label' => __('Report Templates'), 'group' => true],
                    // Data
                    ['route' => 'settings.data-retention', 'label' => __('Data Retention'), 'group' => true],
                    ['route' => 'settings.application-backup', 'label' => __('Application Backup'), 'group' => false],
                    // Team
                    ['route' => 'settings.users', 'label' => __('Users'), 'group' => true],
                ], $tabs);
            }
        @endphp

        @foreach($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
               class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition
                      {{ $tab['group'] ? 'ml-6' : '' }}
                      {{ request()->routeIs($tab['route'])
                          ? 'border-purple-500 text-purple-600'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
