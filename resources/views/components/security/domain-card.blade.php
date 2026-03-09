@props(['monitor'])

<x-ui.card>
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900">Domain Registration</h3>
        @if($monitor)
            <x-ui.badge :variant="$monitor->status_color">
                {{ $monitor->status_label }}
            </x-ui.badge>
        @else
            <x-ui.badge variant="gray">Not Monitored</x-ui.badge>
        @endif
    </div>

    @if($monitor)
        <div class="mb-4 space-y-2">
            @if($monitor->expires_at)
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">Expires</span>
                    <span class="font-medium text-gray-900">
                        {{ $monitor->expires_at->format('M d, Y') }}
                        @if($monitor->days_remaining !== null)
                            <span class="ml-1 {{ $monitor->days_remaining <= 0 ? 'text-red-600' : ($monitor->days_remaining <= 30 ? 'text-yellow-600' : 'text-green-600') }}">
                                ({{ $monitor->days_remaining }} days)
                            </span>
                        @endif
                    </span>
                </div>
            @endif
            @if($monitor->registrar)
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">Registrar</span>
                    <span class="font-medium text-gray-900">{{ $monitor->registrar }}</span>
                </div>
            @endif
        </div>

        <details class="mb-4">
            <summary class="cursor-pointer text-sm font-medium text-purple-600 hover:text-purple-700">Show Details</summary>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Domain</dt>
                    <dd class="text-gray-900">{{ $monitor->domain }}</dd>
                </div>
                @if($monitor->registrar)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Registrar</dt>
                        <dd class="text-gray-900">
                            {{ $monitor->registrar }}
                            @if($monitor->registrar_url)
                                <a href="{{ $monitor->registrar_url }}" target="_blank" class="ml-1 text-purple-600 hover:text-purple-700">&rarr;</a>
                            @endif
                        </dd>
                    </div>
                @endif
                @if($monitor->registered_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Registered</dt>
                        <dd class="text-gray-900">{{ $monitor->registered_at->format('M d, Y') }}</dd>
                    </div>
                @endif
                @if($monitor->expires_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Expires</dt>
                        <dd class="text-gray-900">{{ $monitor->expires_at->format('M d, Y') }}</dd>
                    </div>
                @endif
                @if($monitor->nameservers && count($monitor->nameservers) > 0)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Nameservers</dt>
                        <dd class="text-gray-900 text-right">
                            @foreach($monitor->nameservers as $ns)
                                <div>{{ $ns }}</div>
                            @endforeach
                        </dd>
                    </div>
                @endif
                @if($monitor->dns_provider)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">DNS Provider</dt>
                        <dd class="text-gray-900">{{ $monitor->dns_provider }}</dd>
                    </div>
                @endif
                @if($monitor->domain_statuses && count($monitor->domain_statuses) > 0)
                    <div>
                        <dt class="text-gray-500 mb-1">Status Flags</dt>
                        <dd class="flex flex-wrap gap-1">
                            @foreach($monitor->domain_statuses as $flag)
                                <span class="inline-block rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $flag }}</span>
                            @endforeach
                        </dd>
                    </div>
                @endif
            </dl>
        </details>

        @if($monitor->error_message)
            <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                {{ $monitor->error_message }}
            </div>
        @endif

        <div class="mb-4 border-t pt-4">
            <h4 class="text-sm font-medium text-gray-700 mb-2">Alert Settings</h4>
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox"
                           class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                           @checked($monitor->alerts_enabled)
                           wire:change="updateDomainAlertSettings($event.target.checked, {{ $monitor->warn_days }})">
                    Alerts enabled
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <span class="text-gray-500">Warn at</span>
                    <select class="rounded-lg border border-gray-300 px-2 py-1 text-sm"
                            wire:change="updateDomainAlertSettings({{ $monitor->alerts_enabled ? 'true' : 'false' }}, $event.target.value)">
                        @foreach([7, 14, 21, 30, 60, 90] as $days)
                            <option value="{{ $days }}" @selected($monitor->warn_days === $days)>{{ $days }} days</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="flex items-center justify-between border-t pt-4">
            <span class="text-xs text-gray-500">
                Last checked: {{ $monitor->last_checked_at?->diffForHumans() ?? 'Never' }}
            </span>
            <x-ui.button variant="secondary" size="sm" wire:click="checkDomainNow" wire:loading.attr="disabled">
                <x-ui.spinner size="xs" class="hidden" wire:loading.class.remove="hidden" wire:target="checkDomainNow" />
                Check Now
            </x-ui.button>
        </div>
    @else
        <p class="text-sm text-gray-500">No domain monitor configured for this site.</p>
    @endif
</x-ui.card>
