<div>
    <x-ui.card>
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Client Sites</h3>
        @if($client->sites->count())
            <div class="space-y-3">
                @foreach($client->sites as $site)
                    <a href="{{ route('sites.overview', $site) }}" class="flex items-center justify-between rounded-lg border p-3 hover:bg-gray-50 transition">
                        <div class="flex items-center gap-3">
                            <img src="https://www.google.com/s2/favicons?domain={{ $site->domain }}&sz=32" alt="" class="h-6 w-6 rounded">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $site->name }}</p>
                                <p class="text-xs text-gray-500">{{ $site->domain }}</p>
                            </div>
                        </div>
                        <x-ui.badge :variant="$site->is_up ? 'green' : 'red'">
                            {{ $site->is_up ? 'Online' : 'Offline' }}
                        </x-ui.badge>
                    </a>
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-500">No sites assigned to this client.</p>
        @endif
    </x-ui.card>
</div>
