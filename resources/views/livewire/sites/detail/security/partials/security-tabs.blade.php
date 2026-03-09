<div class="mb-6 border-b border-gray-200">
    <nav class="-mb-px flex gap-6">
        @php
            $tabs = [
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

        @foreach($tabs as $tab)
            <a href="{{ route($tab['route'], $site) }}"
               class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition
                      {{ request()->routeIs($tab['routeIs'])
                          ? 'border-purple-500 text-purple-600'
                          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
