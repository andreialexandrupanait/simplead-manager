<div>
    <a href="{{ route('sites.show', $site) }}" class="block">
        <x-ui.card :padding="false" class="transition hover:shadow-md hover:ring-gray-950/10">
            <div class="p-4">
                {{-- Header: Favicon + Name + Status --}}
                <div class="flex items-start gap-3">
                    <x-site-favicon :site="$site" size="lg" />
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate text-sm font-semibold text-gray-900">{{ $site->name }}</h3>
                        <p class="truncate text-xs text-gray-500">{{ $site->domain }}</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                        {{ match($healthLevel->value) {
                            'healthy' => 'bg-green-50 text-green-700',
                            'warning' => 'bg-yellow-50 text-yellow-700',
                            'critical' => 'bg-red-50 text-red-700',
                            default => 'bg-gray-50 text-gray-600',
                        } }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $healthLevel->bgColor() }}"></span>
                        {{ $healthLevel->label() }}
                    </span>
                </div>

                {{-- Stats Row --}}
                <div class="mt-3 flex items-center gap-4 text-xs text-gray-500">
                    @if($site->health_score !== null)
                        <span title="Health score">
                            <span class="font-medium {{ $site->health_score >= 75 ? 'text-green-600' : ($site->health_score >= 50 ? 'text-yellow-600' : 'text-red-600') }}">{{ $site->health_score }}</span>/100
                        </span>
                    @endif

                    @if($site->uptime_percentage !== null)
                        <span title="Uptime">{{ number_format((float) $site->uptime_percentage, 1) }}%</span>
                    @endif

                    @if($site->pending_updates_count > 0)
                        <span class="text-yellow-600" title="Pending updates">{{ $site->pending_updates_count }} updates</span>
                    @endif
                </div>

                {{-- Footer: WP version + Last sync --}}
                <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-2.5 text-xs text-gray-400">
                    <span>{{ $site->wp_version ? 'WP ' . $site->wp_version : '' }}</span>
                    <span>{{ $site->last_synced_at?->diffForHumans() ?? 'Never synced' }}</span>
                </div>
            </div>
        </x-ui.card>
    </a>
</div>
