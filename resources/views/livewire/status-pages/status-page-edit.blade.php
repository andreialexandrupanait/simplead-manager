<div>
    <x-ui.flash-alert type="success" key="success" />

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ $statusPage ? __('Edit Status Page') : __('Create Status Page') }}</h2>
            @if($statusPage)
                <p class="text-sm text-gray-500">
                    <a href="{{ url('/status/' . $statusPage->slug) }}" target="_blank" class="text-purple-600 hover:text-purple-700">
                        {{ url('/status/' . $statusPage->slug) }}
                        <svg class="inline h-3 w-3 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                    </a>
                </p>
            @endif
        </div>
        <a href="{{ route('settings.status-pages') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; {{ __('Back to list') }}</a>
    </div>

    <div class="space-y-6">
        {{-- Settings --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Settings') }}</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Title') }}</label>
                    <x-ui.input type="text" wire:model.live="title" placeholder="{{ __('My Status Page') }}" />
                    @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Slug') }}</label>
                    <div class="flex items-center">
                        <span class="text-sm text-gray-400 mr-1">/status/</span>
                        <x-ui.input type="text" wire:model="slug" placeholder="my-status-page" />
                    </div>
                    @error('slug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Description') }}</label>
                    <textarea wire:model="description" rows="2" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __('Optional description shown on the status page') }}"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Logo URL') }}</label>
                    <x-ui.input type="text" wire:model="logoUrl" placeholder="https://example.com/logo.png" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Primary Color') }}</label>
                    <div class="flex items-center gap-2">
                        <input type="color" wire:model="primaryColor" class="h-9 w-9 rounded cursor-pointer border-0 p-0">
                        <x-ui.input type="text" wire:model="primaryColor" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Client') }}</label>
                    <x-ui.select wire:model="clientId">
                        <option value="">{{ __('No client') }}</option>
                        @foreach($this->clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Incident History (days)') }}</label>
                    <x-ui.input type="number" wire:model="incidentHistoryDays" min="1" max="365" />
                </div>
            </div>
        </x-ui.card>

        {{-- Custom Domain --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Custom Domain') }}</h3>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Custom Domain') }}</label>
                <x-ui.input type="text" wire:model="customDomain" placeholder="status.yourdomain.com" />
            </div>
            <div class="mt-3 rounded-lg bg-blue-50 border border-blue-200 p-3">
                <p class="text-sm font-medium text-blue-800 mb-1">{{ __('Setup Instructions') }}</p>
                <ol class="text-xs text-blue-700 space-y-1 list-decimal list-inside">
                    <li>{{ __('Add a CNAME record for your custom domain pointing to') }} <code class="font-mono bg-blue-100 px-1 rounded">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</code></li>
                    <li>{{ __('Enter the custom domain above and save') }}</li>
                    <li>{{ __('Ensure your DNS changes have propagated (this may take up to 24 hours)') }}</li>
                    <li>{{ __('If using HTTPS, configure your SSL certificate to cover the custom domain') }}</li>
                </ol>
            </div>
        </x-ui.card>

        {{-- Display Options --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Display Options') }}</h3>
            <div class="space-y-3">
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="isPublic" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-700">{{ __('Public (visible to anyone with the URL)') }}</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="showUptimePercentage" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-700">{{ __('Show uptime percentage') }}</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="showResponseTime" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-700">{{ __('Show average response time') }}</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="showIncidentHistory" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-700">{{ __('Show incident history') }}</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="autoIncidents" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-700">{{ __('Auto-create incidents on downtime') }}</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="showSla" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-700">{{ __('Show SLA compliance') }}</span>
                </label>
            </div>

            <div class="mt-3" x-show="$wire.showSla" x-cloak>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('SLA Target (%)') }}</label>
                <x-ui.input type="number" wire:model="slaTarget" step="0.01" min="90" max="100" placeholder="99.90" class="w-32" />
                <p class="mt-1 text-xs text-gray-500">{{ __('e.g. 99.9 for "three nines" availability') }}</p>
            </div>
        </x-ui.card>

        {{-- Password Protection --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Access Control') }}</h3>
            @if($statusPage?->password_hash)
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">{{ __('This page is password-protected.') }}</p>
                    <x-ui.button variant="secondary" size="sm" wire:click="removePassword">{{ __('Remove Password') }}</x-ui.button>
                </div>
            @endif
            <div class="mt-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $statusPage?->password_hash ? __('Change Password') : __('Set Password (optional)') }}</label>
                <x-ui.input type="password" wire:model="password" placeholder="{{ __('Leave blank for no password') }}" />
            </div>
        </x-ui.card>

        {{-- Sites --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Sites') }}</h3>
            <p class="text-sm text-gray-500 mb-3">{{ __('Select which sites to display on this status page.') }}</p>
            <div class="space-y-2 max-h-64 overflow-y-auto">
                @foreach($this->availableSites as $site)
                    <label class="flex items-center gap-3 rounded-lg border border-gray-200 p-3 hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" wire:model="selectedSites" value="{{ $site->id }}" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <div>
                            <span class="text-sm font-medium text-gray-900">{{ $site->name }}</span>
                            <span class="text-xs text-gray-500 ml-2">{{ parse_url($site->url, PHP_URL_HOST) }}</span>
                        </div>
                    </label>
                @endforeach
            </div>
        </x-ui.card>

        {{-- Site Display Order --}}
        @if($statusPage && !empty($selectedSites) && $this->orderedSites->isNotEmpty())
            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Site Display Order') }}</h3>
                <p class="text-sm text-gray-500 mb-3">{{ __('Reorder how sites appear on the status page.') }}</p>
                <div class="space-y-2">
                    @foreach($this->orderedSites as $index => $sps)
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3">
                            <div class="flex items-center gap-3">
                                <span class="text-xs font-medium text-gray-400 w-5 text-center">{{ $index + 1 }}</span>
                                <span class="text-sm font-medium text-gray-900">{{ $sps->site?->name ?? __('Unknown') }}</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="moveSiteUp({{ $sps->site_id }})"
                                    @if($index === 0) disabled @endif
                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 disabled:opacity-30 disabled:cursor-not-allowed">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </button>
                                <button wire:click="moveSiteDown({{ $sps->site_id }})"
                                    @if($loop->last) disabled @endif
                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 disabled:opacity-30 disabled:cursor-not-allowed">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        {{-- Save Button --}}
        <div class="flex justify-end">
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                {{ $statusPage ? __('Save Changes') : __('Create Status Page') }}
            </x-ui.button>
        </div>

        {{-- Incidents (only for existing pages) --}}
        @if($statusPage)
            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Incidents') }}</h3>
                </div>

                {{-- Create Incident --}}
                <div class="rounded-lg border border-gray-200 p-4 mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-sm font-medium text-gray-900">{{ __('Create Incident') }}</h4>
                        @if($this->incidentTemplates->isNotEmpty())
                            <x-ui.select wire:change="applyIncidentTemplate($event.target.value)" class="w-auto text-xs">
                                <option value="">{{ __('Use template...') }}</option>
                                @foreach($this->incidentTemplates as $tmpl)
                                    <option value="{{ $tmpl->id }}">{{ $tmpl->name }}</option>
                                @endforeach
                            </x-ui.select>
                        @endif
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <x-ui.input type="text" wire:model="incidentTitle" placeholder="{{ __('Incident title') }}" />
                        </div>
                        <div>
                            <x-ui.select wire:model="incidentSeverity">
                                <option value="minor">{{ __('Minor') }}</option>
                                <option value="major">{{ __('Major') }}</option>
                                <option value="critical">{{ __('Critical') }}</option>
                            </x-ui.select>
                        </div>
                        <div class="sm:col-span-2">
                            <textarea wire:model="incidentDescription" rows="2" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __('Description (optional)') }}"></textarea>
                        </div>
                        <div>
                            <x-ui.select wire:model="incidentSiteId" class="mb-2">
                                <option value="">{{ __('All sites') }}</option>
                                @foreach($this->availableSites->whereIn('id', $selectedSites) as $site)
                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.button wire:click="createIncident" size="sm" class="w-full">{{ __('Create Incident') }}</x-ui.button>
                        </div>
                    </div>
                </div>

                {{-- Incident List --}}
                @if($this->incidents->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('No incidents recorded.') }}</p>
                @else
                    <div class="space-y-3">
                        @foreach($this->incidents as $incident)
                            <div class="rounded-lg border border-gray-200 p-4">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <x-ui.badge :variant="$incident->severity_color">{{ ucfirst($incident->severity) }}</x-ui.badge>
                                            <x-ui.badge :variant="$incident->status === 'resolved' ? 'green' : 'blue'">{{ $incident->status_label }}</x-ui.badge>
                                            @if($incident->auto_created)
                                                <span class="text-xs text-gray-400">{{ __('Auto') }}</span>
                                            @endif
                                        </div>
                                        <h4 class="mt-1 text-sm font-medium text-gray-900">{{ $incident->title }}</h4>
                                        <p class="text-xs text-gray-500">
                                            {{ $incident->started_at?->format('M d, Y H:i') }}
                                            @if($incident->site)
                                                &middot; {{ $incident->site->name }}
                                            @endif
                                            @if($incident->duration)
                                                &middot; {{ __('Duration') }}: {{ $incident->duration }}
                                            @endif
                                        </p>
                                    </div>
                                    @if($incident->status !== 'resolved')
                                        <div class="flex items-center gap-1">
                                            <x-ui.select wire:change="updateIncidentStatus({{ $incident->id }}, $event.target.value)" class="text-xs">
                                                @foreach(['investigating', 'identified', 'monitoring', 'resolved'] as $status)
                                                    <option value="{{ $status }}" {{ $incident->status === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                                                @endforeach
                                            </x-ui.select>
                                            <x-ui.button variant="secondary" size="sm" wire:click="resolveIncident({{ $incident->id }})">{{ __('Resolve') }}</x-ui.button>
                                        </div>
                                    @endif
                                </div>

                                {{-- Updates --}}
                                @if($incident->updates->isNotEmpty())
                                    <div class="mt-3 border-t border-gray-100 pt-3 space-y-2">
                                        @foreach($incident->updates->take(3) as $update)
                                            <div class="flex items-start gap-2 text-xs">
                                                <span class="font-medium text-gray-600">{{ ucfirst($update->status) }}</span>
                                                <span class="text-gray-500">{{ $update->message }}</span>
                                                <span class="text-gray-400 ml-auto whitespace-nowrap">{{ $update->created_at->diffForHumans() }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Add Update --}}
                                @if($incident->status !== 'resolved')
                                    <div class="mt-3 border-t border-gray-100 pt-3">
                                        <div class="flex items-center gap-2">
                                            <x-ui.input type="text" wire:model="updateMessage" class="flex-1 text-xs" placeholder="{{ __('Add an update...') }}" />
                                            <x-ui.select wire:model="updateStatus" class="text-xs">
                                                @foreach(['investigating', 'identified', 'monitoring', 'resolved'] as $status)
                                                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                                                @endforeach
                                            </x-ui.select>
                                            <x-ui.button size="sm" wire:click="addIncidentUpdate({{ $incident->id }})">{{ __('Post') }}</x-ui.button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endif
    </div>
</div>
