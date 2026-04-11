<div class="mb-6 border-b border-gray-200">
    <nav class="-mb-px flex gap-4 overflow-x-auto">
        @php
            $tabs = [
                ['route' => 'sites.seo', 'label' => __('Overview'), 'active' => request()->routeIs('sites.seo') && !request()->routeIs('sites.seo.*')],
                ['route' => 'sites.seo.health-report', 'label' => __('Health Report'), 'active' => request()->routeIs('sites.seo.health-report')],
                ['route' => 'sites.seo.audit', 'label' => __('Audit Results'), 'active' => request()->routeIs('sites.seo.audit')],
                ['route' => 'sites.seo.keywords', 'label' => __('Keywords'), 'active' => request()->routeIs('sites.seo.keywords')],
                ['route' => 'sites.seo.technical', 'label' => __('Technical'), 'active' => request()->routeIs('sites.seo.technical')],
                ['route' => 'sites.seo.performance', 'label' => __('Performance'), 'active' => request()->routeIs('sites.seo.performance')],
                ['route' => 'sites.seo.backlinks', 'label' => __('Backlinks'), 'active' => request()->routeIs('sites.seo.backlinks')],
                ['route' => 'sites.seo.competitors', 'label' => __('Competitors'), 'active' => request()->routeIs('sites.seo.competitors')],
            ];
        @endphp

        @foreach($tabs as $tab)
            <a href="{{ route($tab['route'], $site) }}"
               class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition
                      {{ $tab['active']
                          ? 'border-purple-500 text-purple-600'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
