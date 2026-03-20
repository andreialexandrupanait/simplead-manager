<div class="mb-6 border-b border-gray-200">
    {{-- Row 1: Security --}}
    <div class="mb-1">
        <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Security</span>
    </div>
    <nav class="-mb-px flex gap-4 overflow-x-auto">
        @php
            $securityTabs = [
                ['route' => 'sites.security', 'routeIs' => 'sites.security', 'label' => __('Overview')],
                ['route' => 'sites.security.hardening', 'routeIs' => 'sites.security.hardening', 'label' => __('Hardening')],
                ['route' => 'sites.security.login', 'routeIs' => 'sites.security.login', 'label' => __('Login Protection')],
                ['route' => 'sites.security.captcha', 'routeIs' => 'sites.security.captcha', 'label' => __('Captcha')],
                ['route' => 'sites.security.scanning', 'routeIs' => 'sites.security.scanning', 'label' => __('Scanning')],
                ['route' => 'sites.security.activity', 'routeIs' => 'sites.security.activity', 'label' => __('Activity')],
                ['route' => 'sites.security.users', 'routeIs' => 'sites.security.users', 'label' => __('Users')],
                ['route' => 'sites.security.ip-management', 'routeIs' => 'sites.security.ip-management', 'label' => __('IP Management')],
            ];
        @endphp

        @foreach($securityTabs as $tab)
            <a href="{{ route($tab['route'], $site) }}"
               class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition
                      {{ request()->routeIs($tab['routeIs'])
                          ? 'border-purple-500 text-purple-600'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>

    {{-- Row 2: Site Tweaks --}}
    <div class="mt-3 mb-1">
        <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Site Tweaks</span>
    </div>
    <nav class="-mb-px flex gap-4 overflow-x-auto">
        @php
            $tweakTabs = [
                ['route' => 'sites.security.performance', 'routeIs' => 'sites.security.performance', 'label' => __('Performance')],
                ['route' => 'sites.security.site-control', 'routeIs' => 'sites.security.site-control', 'label' => __('Site Control')],
            ];
            $comingSoonTabs = [
                ['route' => 'sites.security.admin-ux', 'label' => __('Admin UX')],
                ['route' => 'sites.security.content-media', 'label' => __('Content & Media')],
                ['route' => 'sites.security.email', 'label' => __('Email')],
            ];
        @endphp

        @foreach($tweakTabs as $tab)
            <a href="{{ route($tab['route'], $site) }}"
               class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition
                      {{ request()->routeIs($tab['routeIs'])
                          ? 'border-purple-500 text-purple-600'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach

        @foreach($comingSoonTabs as $tab)
            <a href="{{ route($tab['route'], $site) }}"
               class="whitespace-nowrap border-b-2 border-transparent px-1 py-3 text-sm font-medium text-gray-300 hover:text-gray-400 transition flex items-center gap-1.5">
                {{ $tab['label'] }}
                <span class="inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 ring-1 ring-inset ring-amber-500/20">Soon</span>
            </a>
        @endforeach
    </nav>
</div>
