<div class="mb-6 flex gap-2">
    @php
        $monitoringTabs = [
            ['route' => 'sites.security.scanning', 'routeIs' => 'sites.security.scanning', 'label' => 'Scanning'],
            ['route' => 'sites.security.activity', 'routeIs' => 'sites.security.activity', 'label' => 'Activity'],
            ['route' => 'sites.security.users', 'routeIs' => 'sites.security.users', 'label' => 'Users'],
        ];
    @endphp

    @foreach($monitoringTabs as $tab)
        <a href="{{ route($tab['route'], $site) }}"
           wire:navigate
           class="rounded-full px-3 py-1.5 text-sm font-medium {{ request()->routeIs($tab['routeIs'])
               ? 'bg-purple-100 text-purple-700'
               : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
