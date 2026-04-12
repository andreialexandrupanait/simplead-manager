<div class="mb-6 border-b border-gray-200">
    <nav class="-mb-px flex gap-6">
        @php
            $tabs = [
                ['route' => 'sites.tweaks', 'routeIs' => 'sites.tweaks', 'label' => __('Overview')],
                ['route' => 'sites.tweaks.performance', 'routeIs' => 'sites.tweaks.performance', 'label' => __('Performance')],
                ['route' => 'sites.tweaks.site-control', 'routeIs' => 'sites.tweaks.site-control', 'label' => __('Site Control')],
                ['route' => 'sites.tweaks.admin-ux', 'routeIs' => 'sites.tweaks.admin-ux', 'label' => __('Admin UX')],
                ['route' => 'sites.tweaks.content-media', 'routeIs' => 'sites.tweaks.content-media', 'label' => __('Content & Media')],
            ];
        @endphp

        @foreach($tabs as $tab)
            <a href="{{ route($tab['route'], $site) }}"
               class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition
                      {{ request()->routeIs($tab['routeIs'])
                          ? 'border-accent-500 text-accent-600'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
