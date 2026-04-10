<div>
    <x-ui.page-header title="{{ __('Keyword Research') }}" subtitle="{{ __('Discover keyword opportunities using Google Autocomplete') }}" />

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Left: Research form + Results (2/3) --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Research form --}}
            <x-ui.card>
                <h3 class="mb-4 text-sm font-semibold text-gray-900">{{ __('New Research') }}</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-5">
                    <div class="sm:col-span-2">
                        <input type="text" wire:model="seedKeyword" placeholder="{{ __('Seed keyword...') }}" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" />
                        @error('seedKeyword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <select wire:model="siteId" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                            <option value="">{{ __('No site') }}</option>
                            @foreach($this->sites as $site)
                                <option value="{{ $site->id }}">{{ $site->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <select wire:model="language" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                            <option value="ro">{{ __('Romanian') }}</option>
                            <option value="en">{{ __('English') }}</option>
                            <option value="de">{{ __('German') }}</option>
                            <option value="fr">{{ __('French') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-ui.button variant="primary" wire:click="startResearch" wire:loading.attr="disabled" wire:target="startResearch" class="w-full">
                            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="startResearch" />
                            {{ __('Research') }}
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>

            {{-- Job progress --}}
            @if($this->hasRunningJobs)
                <div wire:poll.3s="checkJobProgress">
                    <x-ui.job-progress job-key="research" :jobs="$trackedJobs" title="{{ __('Researching keywords...') }}" />
                </div>
            @endif

            {{-- Active Result --}}
            @if($this->activeResult)
                @php $result = $this->activeResult; @endphp
                <x-ui.card>
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">
                            {{ __('Results for') }}: <span class="text-purple-600">{{ $result->seed_keyword }}</span>
                            <span class="text-xs text-gray-400">({{ count($result->suggestions ?? []) }} {{ __('keywords') }})</span>
                        </h3>
                    </div>

                    {{-- Clusters --}}
                    @if(!empty($result->clusters))
                        <h4 class="mb-2 text-xs font-semibold uppercase text-gray-500">{{ __('Keyword Clusters') }}</h4>
                        <div class="mb-4 space-y-3">
                            @foreach($result->clusters as $cluster)
                                <div class="rounded-lg border border-gray-200 p-3">
                                    <div class="mb-2 text-sm font-medium text-gray-700">{{ $cluster['label'] }} <span class="text-xs text-gray-400">({{ count($cluster['keywords']) }})</span></div>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($cluster['keywords'] as $kw)
                                            <span class="group inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700">
                                                {{ $kw }}
                                                @if($siteId)
                                                    <button wire:click="addToTracking('{{ addslashes($kw) }}')" class="hidden text-purple-500 hover:text-purple-700 group-hover:inline" title="{{ __('Add to tracking') }}">+</button>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- GSC Data Overlay --}}
                    @if(!empty($result->gsc_data))
                        <h4 class="mb-2 text-xs font-semibold uppercase text-gray-500">{{ __('Search Console Data') }}</h4>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="py-2 text-left font-medium text-gray-500">{{ __('Keyword') }}</th>
                                        <th class="py-2 text-center font-medium text-gray-500">{{ __('Clicks') }}</th>
                                        <th class="py-2 text-center font-medium text-gray-500">{{ __('Impressions') }}</th>
                                        <th class="py-2 text-center font-medium text-gray-500">{{ __('CTR') }}</th>
                                        <th class="py-2 text-center font-medium text-gray-500">{{ __('Position') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach(array_slice($result->gsc_data, 0, 30) as $row)
                                        <tr class="border-b border-gray-100">
                                            <td class="py-1.5 text-gray-700">{{ $row['keyword'] ?? '' }}</td>
                                            <td class="py-1.5 text-center text-gray-600">{{ $row['clicks'] ?? 0 }}</td>
                                            <td class="py-1.5 text-center text-gray-600">{{ $row['impressions'] ?? 0 }}</td>
                                            <td class="py-1.5 text-center text-gray-600">{{ $row['ctr'] ?? 0 }}%</td>
                                            <td class="py-1.5 text-center text-gray-600">{{ $row['position'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- All Suggestions --}}
                    @if(!empty($result->suggestions))
                        <h4 class="mb-2 mt-4 text-xs font-semibold uppercase text-gray-500">{{ __('All Suggestions') }} ({{ count($result->suggestions) }})</h4>
                        <div class="max-h-64 overflow-y-auto">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($result->suggestions as $suggestion)
                                    <span class="rounded-full bg-gray-50 px-2.5 py-1 text-xs text-gray-600">{{ $suggestion }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-ui.card>
            @endif
        </div>

        {{-- Right: Recent results (1/3) --}}
        <div>
            <x-ui.card>
                <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Recent Research') }}</h3>
                @if($this->recentResults->isEmpty())
                    <p class="text-sm text-gray-400">{{ __('No research history.') }}</p>
                @else
                    <div class="space-y-2">
                        @foreach($this->recentResults as $r)
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2">
                                <button wire:click="viewResult({{ $r->id }})" class="text-left text-sm">
                                    <div class="font-medium text-gray-700 hover:text-purple-600">{{ $r->seed_keyword }}</div>
                                    <div class="text-xs text-gray-400">{{ count($r->suggestions ?? []) }} kw &middot; {{ $r->created_at->diffForHumans() }}</div>
                                </button>
                                <button wire:click="deleteResult({{ $r->id }})" class="text-xs text-red-500 hover:text-red-700">&times;</button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
