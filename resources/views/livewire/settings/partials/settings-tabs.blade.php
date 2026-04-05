<div class="sticky top-16 z-10 -mx-6 lg:-mx-8 px-6 lg:px-8 mb-6 border-b border-gray-200 bg-gray-50/95 backdrop-blur">
    <nav class="-mb-px flex gap-1 overflow-x-auto scrollbar-hide">
        @php
            $tabs = [['route' => 'settings.profile', 'label' => __('Profile')]];

            if (auth()->user()->isAdmin()) {
                $tabs = array_merge([
                    ['route' => 'settings.general', 'label' => __('General')],
                    ['route' => 'settings.notifications', 'label' => __('Notifications')],
                    ['route' => 'settings.email', 'label' => __('Email')],
                    ['route' => 'settings.integrations', 'label' => __('Integrations')],
                    ['route' => 'settings.wordpress', 'label' => __('WordPress')],
                    ['route' => 'settings.status-pages', 'label' => __('Status Pages')],
                    ['route' => 'settings.report-templates', 'label' => __('Report Templates')],
                    ['route' => 'settings.data-retention', 'label' => __('Data Retention')],
                    ['route' => 'settings.ai-incident-response', 'label' => __('AI Incident Response')],
                    ['route' => 'settings.application-backup', 'label' => __('Application Backup')],
                    ['route' => 'settings.users', 'label' => __('Users')],
                ], $tabs);
            }
        @endphp

        @foreach($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
               class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium transition
                      {{ request()->routeIs($tab['route'])
                          ? 'border-purple-500 text-purple-600'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
