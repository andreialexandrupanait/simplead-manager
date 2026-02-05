<div>
    <div class="mb-6 flex justify-end">
        <x-ui.button wire:click="syncNow" wire:loading.attr="disabled">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span wire:loading.remove wire:target="syncNow">Sync Now</span>
            <span wire:loading wire:target="syncNow">Syncing...</span>
        </x-ui.button>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    {{-- Stats Cards --}}
    @if($this->todayStats)
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <x-ui.card>
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-900">{{ $this->todayStats->currency }} {{ number_format($this->todayStats->revenue, 2) }}</p>
                    <p class="text-xs text-gray-500">Revenue Today</p>
                </div>
            </x-ui.card>

            <x-ui.card>
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-900">{{ $this->todayStats->orders_count }}</p>
                    <p class="text-xs text-gray-500">Orders Today</p>
                </div>
            </x-ui.card>

            <x-ui.card>
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-900">{{ $this->todayStats->currency }} {{ number_format($this->todayStats->average_order_value, 2) }}</p>
                    <p class="text-xs text-gray-500">Avg Order Value</p>
                </div>
            </x-ui.card>

            <x-ui.card>
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-900">{{ $this->todayStats->products_sold_count }}</p>
                    <p class="text-xs text-gray-500">Products Sold</p>
                </div>
            </x-ui.card>
        </div>

        {{-- Refund Summary --}}
        @if($this->todayStats->refunds_count > 0)
            <div class="mt-4 rounded-lg bg-yellow-50 border border-yellow-200 p-4">
                <div class="flex items-center gap-4 text-sm">
                    <span class="text-yellow-700 font-medium">Refunds Today:</span>
                    <span class="text-yellow-600">{{ $this->todayStats->refunds_count }} refund(s) totaling {{ $this->todayStats->currency }} {{ number_format($this->todayStats->refunds_amount, 2) }}</span>
                </div>
            </div>
        @endif
    @else
        <x-ui.card>
            <div class="p-8 text-center">
                <div class="mb-3 inline-flex rounded-full bg-gray-100 p-3">
                    <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-gray-900">No Sales Data Yet</p>
                <p class="mt-1 text-xs text-gray-500">Click "Sync Now" to fetch WooCommerce data.</p>
            </div>
        </x-ui.card>
    @endif

    {{-- Revenue Chart --}}
    @if($this->revenueChart->count() > 1)
        <x-ui.card :padding="false" class="mt-6">
            <div class="border-b p-4">
                <h3 class="text-lg font-semibold text-gray-900">Revenue (Last 30 Days)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2">Revenue</th>
                            <th class="px-4 py-2">Orders</th>
                            <th class="px-4 py-2">Avg Order</th>
                            <th class="px-4 py-2">Products</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($this->revenueChart->reverse() as $stat)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 text-gray-500">{{ $stat->date->format('M d') }}</td>
                                <td class="px-4 py-2 font-medium text-gray-900">{{ $stat->currency }} {{ number_format($stat->revenue, 2) }}</td>
                                <td class="px-4 py-2">{{ $stat->orders_count }}</td>
                                <td class="px-4 py-2">{{ $stat->currency }} {{ number_format($stat->average_order_value, 2) }}</td>
                                <td class="px-4 py-2">{{ $stat->products_sold_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    {{-- Alerts --}}
    @if($this->alerts->count() > 0)
        <x-ui.card :padding="false" class="mt-6">
            <div class="border-b p-4">
                <h3 class="text-lg font-semibold text-gray-900">
                    Alerts
                    <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                        {{ $this->alerts->count() }}
                    </span>
                </h3>
            </div>
            <div class="divide-y">
                @foreach($this->alerts as $alert)
                    <div class="flex items-center justify-between px-4 py-3">
                        <div class="flex items-center gap-3">
                            <x-ui.badge :variant="match($alert->type) { 'out_of_stock' => 'red', 'low_stock' => 'yellow', 'failed_order' => 'red', 'high_refunds' => 'yellow', default => 'gray' }">
                                {{ ucfirst(str_replace('_', ' ', $alert->type)) }}
                            </x-ui.badge>
                            <div>
                                @if($alert->product_name)
                                    <span class="text-sm font-medium text-gray-900">{{ $alert->product_name }}</span>
                                @endif
                                <p class="text-xs text-gray-500">{{ $alert->message }}</p>
                            </div>
                        </div>
                        <x-ui.button size="sm" variant="secondary" wire:click="acknowledgeAlert({{ $alert->id }})">
                            Acknowledge
                        </x-ui.button>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif
</div>
