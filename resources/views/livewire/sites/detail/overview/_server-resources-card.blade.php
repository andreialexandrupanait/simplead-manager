@if($site->is_connected)
<x-ui.module-card title="Server Resources" icon="activity" tone="dark">
    <x-slot:actions>
        @if($serverResourcesLoadedAt)
            <span class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($serverResourcesLoadedAt)->diffForHumans() }}</span>
        @endif
        <button type="button" wire:click="loadServerResources" wire:loading.attr="disabled" wire:target="loadServerResources"
                class="text-gray-400 hover:text-gray-600">
            <x-icons.refresh-cw class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="loadServerResources" />
        </button>
    </x-slot:actions>

    <div @if(!$serverResources || $this->serverResourcesIsStale) wire:init="loadServerResources" @endif>
        @if($serverResources)
            <div class="space-y-3">
                {{-- CPU --}}
                @if(isset($serverResources['cpu_usage']))
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <span class="text-xs text-gray-600">CPU</span>
                        <span class="text-xs font-medium text-gray-900">{{ round($serverResources['cpu_usage']) }}%</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-gray-100">
                        @php $cpuColor = $serverResources['cpu_usage'] > 80 ? 'bg-red-500' : ($serverResources['cpu_usage'] > 50 ? 'bg-yellow-500' : 'bg-green-500'); @endphp
                        <div class="h-1.5 rounded-full {{ $cpuColor }}" style="width: {{ min($serverResources['cpu_usage'], 100) }}%"></div>
                    </div>
                </div>
                @endif

                {{-- Memory --}}
                @if(isset($serverResources['memory_used']) && isset($serverResources['memory_total']))
                @php
                    $memPct = $serverResources['memory_total'] > 0 ? round(($serverResources['memory_used'] / $serverResources['memory_total']) * 100) : 0;
                    $memColor = $memPct > 80 ? 'bg-red-500' : ($memPct > 50 ? 'bg-yellow-500' : 'bg-green-500');
                    $memUsedMb = round($serverResources['memory_used'] / 1024 / 1024);
                    $memTotalMb = round($serverResources['memory_total'] / 1024 / 1024);
                @endphp
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <span class="text-xs text-gray-600">Memory</span>
                        <span class="text-xs font-medium text-gray-900">{{ $memUsedMb }} / {{ $memTotalMb }} MB</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-gray-100">
                        <div class="h-1.5 rounded-full {{ $memColor }}" style="width: {{ min($memPct, 100) }}%"></div>
                    </div>
                </div>
                @endif

                {{-- Disk --}}
                @if(isset($serverResources['disk_used']) && isset($serverResources['disk_total']))
                @php
                    $diskPct = $serverResources['disk_total'] > 0 ? round(($serverResources['disk_used'] / $serverResources['disk_total']) * 100) : 0;
                    $diskColor = $diskPct > 90 ? 'bg-red-500' : ($diskPct > 70 ? 'bg-yellow-500' : 'bg-green-500');
                    $diskUsedGb = round($serverResources['disk_used'] / 1024 / 1024 / 1024, 1);
                    $diskTotalGb = round($serverResources['disk_total'] / 1024 / 1024 / 1024, 1);
                @endphp
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <span class="text-xs text-gray-600">Disk</span>
                        <span class="text-xs font-medium text-gray-900">{{ $diskUsedGb }} / {{ $diskTotalGb }} GB</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-gray-100">
                        <div class="h-1.5 rounded-full {{ $diskColor }}" style="width: {{ min($diskPct, 100) }}%"></div>
                    </div>
                </div>
                @endif

                {{-- Load Average --}}
                @if(isset($serverResources['load_average']))
                <x-ui.info-row label="Load Avg">
                    <span class="font-mono">{{ implode(' / ', array_map(fn($v) => number_format($v, 2), array_slice($serverResources['load_average'], 0, 3))) }}</span>
                </x-ui.info-row>
                @endif

                {{-- Uptime --}}
                @if(isset($serverResources['uptime']))
                <x-ui.info-row label="Uptime">{{ $serverResources['uptime'] }}</x-ui.info-row>
                @endif
            </div>
        @else
            <div class="flex items-center justify-center py-6">
                <x-icons.refresh-cw class="h-4 w-4 animate-spin text-gray-400" />
                <span class="ml-2 text-sm text-gray-400">Loading...</span>
            </div>
        @endif
    </div>
</x-ui.module-card>
@endif
