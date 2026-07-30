{{-- Six tabs, grouped by what you came to do.

     There were ten, split by whichever component happened to own the fields:
     "Email" held nothing but SMTP, "WordPress" was a single button, and
     "Application Backup" and "Data Retention" were two halves of the same
     question. --}}
<div class="sticky top-16 z-10 -mx-6 lg:-mx-8 px-6 lg:px-8 mb-6 border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
    <nav class="-mb-px flex gap-1 overflow-x-auto scrollbar-hide">
        @php
            $tabs = [
                ['route' => 'settings.account', 'label' => __('Account')],
            ];

            if (auth()->user()->isAdmin()) {
                $tabs = array_merge([
                    ['route' => 'settings.general', 'label' => __('General')],
                    ['route' => 'settings.notifications', 'label' => __('Notifications')],
                    ['route' => 'settings.integrations', 'label' => __('Integrations')],
                    ['route' => 'settings.data', 'label' => __('Backup & Data')],
                    ['route' => 'settings.report-templates', 'label' => __('Reports')],
                ], $tabs);
            }
        @endphp

        @foreach($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
               class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium transition
                      {{ request()->routeIs($tab['route'])
                          ? 'border-accent-500 text-accent-600 dark:text-accent-400'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
