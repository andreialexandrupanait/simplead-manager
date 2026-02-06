@props(['certificate'])

<x-ui.card>
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900">SSL Certificate</h3>
        @if($certificate)
            <x-ui.badge :variant="$certificate->status_color">
                {{ $certificate->status_label }}
            </x-ui.badge>
        @else
            <x-ui.badge variant="gray">No HTTPS</x-ui.badge>
        @endif
    </div>

    @if($certificate)
        <div class="mb-4 space-y-2">
            @if($certificate->expires_at)
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">Expires</span>
                    <span class="font-medium text-gray-900">
                        {{ $certificate->expires_at->format('M d, Y') }}
                        @if($certificate->days_remaining !== null)
                            <span class="ml-1 {{ $certificate->days_remaining <= 0 ? 'text-red-600' : ($certificate->days_remaining <= 30 ? 'text-yellow-600' : 'text-green-600') }}">
                                ({{ $certificate->days_remaining }} days)
                            </span>
                        @endif
                    </span>
                </div>
            @endif
            @if($certificate->issuer)
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">Issuer</span>
                    <span class="font-medium text-gray-900">{{ $certificate->issuer }}</span>
                </div>
            @endif
        </div>

        <details class="mb-4">
            <summary class="cursor-pointer text-sm font-medium text-purple-600 hover:text-purple-700">Show Details</summary>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Domain</dt>
                    <dd class="text-gray-900">{{ $certificate->domain }}</dd>
                </div>
                @if($certificate->san_domains && count($certificate->san_domains) > 0)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">SAN Domains</dt>
                        <dd class="text-gray-900 text-right">{{ implode(', ', $certificate->san_domains) }}</dd>
                    </div>
                @endif
                @if($certificate->protocol)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Protocol</dt>
                        <dd class="text-gray-900">{{ $certificate->protocol }}</dd>
                    </div>
                @endif
                @if($certificate->cipher)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Cipher</dt>
                        <dd class="text-gray-900">{{ $certificate->cipher }}</dd>
                    </div>
                @endif
                @if($certificate->key_size)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Key Size</dt>
                        <dd class="text-gray-900">{{ $certificate->key_size }} bits</dd>
                    </div>
                @endif
                @if($certificate->signature_algorithm)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Signature</dt>
                        <dd class="text-gray-900">{{ $certificate->signature_algorithm }}</dd>
                    </div>
                @endif
                @if($certificate->issued_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Issued</dt>
                        <dd class="text-gray-900">{{ $certificate->issued_at->format('M d, Y') }}</dd>
                    </div>
                @endif
                @if($certificate->expires_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Expires</dt>
                        <dd class="text-gray-900">{{ $certificate->expires_at->format('M d, Y') }}</dd>
                    </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-gray-500">Chain Valid</dt>
                    <dd>
                        <x-ui.badge :variant="$certificate->chain_valid ? 'green' : 'red'">
                            {{ $certificate->chain_valid ? 'Yes' : 'No' }}
                        </x-ui.badge>
                    </dd>
                </div>
            </dl>
        </details>

        @if($certificate->error_message)
            <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                {{ $certificate->error_message }}
            </div>
        @endif

        <div class="mb-4 border-t pt-4">
            <h4 class="text-sm font-medium text-gray-700 mb-2">Alert Settings</h4>
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox"
                           class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                           @checked($certificate->alerts_enabled)
                           wire:change="updateSslAlertSettings($event.target.checked, {{ $certificate->warn_days }})">
                    Alerts enabled
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <span class="text-gray-500">Warn at</span>
                    <select class="rounded-lg border border-gray-300 px-2 py-1 text-sm"
                            wire:change="updateSslAlertSettings({{ $certificate->alerts_enabled ? 'true' : 'false' }}, $event.target.value)">
                        @foreach([7, 14, 21, 30, 60, 90] as $days)
                            <option value="{{ $days }}" @selected($certificate->warn_days === $days)>{{ $days }} days</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="flex items-center justify-between border-t pt-4">
            <span class="text-xs text-gray-500">
                Last checked: {{ $certificate->last_checked_at?->diffForHumans() ?? 'Never' }}
            </span>
            <x-ui.button variant="secondary" size="sm" wire:click="checkSslNow" wire:loading.attr="disabled">
                <x-ui.spinner size="xs" class="hidden" wire:loading.class.remove="hidden" wire:target="checkSslNow" />
                Check Now
            </x-ui.button>
        </div>
    @else
        <p class="text-sm text-gray-500">This site does not use HTTPS. No SSL certificate to monitor.</p>
    @endif
</x-ui.card>
