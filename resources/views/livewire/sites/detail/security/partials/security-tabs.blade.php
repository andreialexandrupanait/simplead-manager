<div class="mb-6 border-b border-gray-200">
    <nav class="-mb-px flex gap-4 overflow-x-auto">
        @php
            $tabs = [
                ['route' => 'sites.security', 'label' => __('Overview'), 'active' => request()->routeIs('sites.security') && !request()->routeIs('sites.security.*')],
                ['route' => 'sites.security.hardening', 'label' => __('Hardening'), 'active' => request()->routeIs('sites.security.hardening')],
                ['route' => 'sites.security.login', 'label' => __('Login'), 'active' => request()->routeIs('sites.security.login')],
                ['route' => 'sites.security.captcha', 'label' => __('Captcha'), 'active' => request()->routeIs('sites.security.captcha')],
                ['route' => 'sites.security.ip-management', 'label' => __('IP Mgmt'), 'active' => request()->routeIs('sites.security.ip-management')],
                ['route' => 'sites.security.scanning', 'label' => __('Scanning'), 'active' => request()->routeIs('sites.security.scanning')],
                ['route' => 'sites.security.activity', 'label' => __('Activity'), 'active' => request()->routeIs('sites.security.activity')],
                ['route' => 'sites.security.users', 'label' => __('Users'), 'active' => request()->routeIs('sites.security.users')],
                ['route' => 'sites.security.performance', 'label' => __('Performance'), 'active' => request()->routeIs('sites.security.performance')],
                ['route' => 'sites.security.site-control', 'label' => __('Site Control'), 'active' => request()->routeIs('sites.security.site-control')],
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
