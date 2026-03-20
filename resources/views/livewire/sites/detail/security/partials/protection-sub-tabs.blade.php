<div class="mb-6 flex gap-2">
    @php
        $protectionTabs = [
            ['route' => 'sites.security.login', 'routeIs' => 'sites.security.login', 'label' => 'Login Protection'],
            ['route' => 'sites.security.captcha', 'routeIs' => 'sites.security.captcha', 'label' => 'Captcha'],
            ['route' => 'sites.security.ip-management', 'routeIs' => 'sites.security.ip-management', 'label' => 'IP Management'],
        ];
    @endphp

    @foreach($protectionTabs as $tab)
        <a href="{{ route($tab['route'], $site) }}"
           wire:navigate
           class="rounded-full px-3 py-1.5 text-sm font-medium {{ request()->routeIs($tab['routeIs'])
               ? 'bg-purple-100 text-purple-700'
               : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
