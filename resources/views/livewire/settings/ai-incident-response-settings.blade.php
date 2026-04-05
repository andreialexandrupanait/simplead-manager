<div>
    @include('livewire.settings.partials.settings-tabs')

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    <form wire:submit="save" class="space-y-6">

        {{-- Master Switch + Status --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-purple-50 shadow-sm ring-1 ring-purple-200">
                        <x-icons.zap class="h-5 w-5 text-purple-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('AI Incident Response') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Automatically diagnose and fix problems on your WordPress sites.') }}</p>
                    </div>
                </div>
                @if($enabled && $apiKey)
                    <x-ui.badge variant="green">{{ __('Active') }}</x-ui.badge>
                @elseif($enabled && !$apiKey)
                    <x-ui.badge variant="yellow">{{ __('Playbooks Only') }}</x-ui.badge>
                @else
                    <x-ui.badge variant="gray">{{ __('Disabled') }}</x-ui.badge>
                @endif
            </div>

            <x-ui.form-group :label="__('Enable Incident Response')" for="enabled" error="enabled">
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model.live="enabled" id="enabled" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                    <span class="ms-3 text-sm text-gray-600">{{ $enabled ? __('Enabled — incidents will be automatically investigated') : __('Disabled — no automatic responses') }}</span>
                </label>
            </x-ui.form-group>

            <div class="mt-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">
                <p class="font-medium">{{ __('How it works:') }}</p>
                <ul class="mt-1 list-disc list-inside space-y-0.5 text-xs text-blue-600">
                    <li>{{ __('Tier 1: Deterministic playbooks for known problems (free, instant)') }}</li>
                    <li>{{ __('Tier 2: AI Agent via Claude API for complex/unknown issues (requires API key)') }}</li>
                    <li>{{ __('Tier 3: Escalation to you when automated resolution fails') }}</li>
                </ul>
            </div>
        </x-ui.card>

        {{-- AI Configuration --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-50 shadow-sm ring-1 ring-violet-200">
                        <x-icons.puzzle class="h-5 w-5 text-violet-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Claude AI Configuration') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Configure the Claude API connection for Tier 2 AI-powered diagnosis.') }}</p>
                    </div>
                </div>
                <x-ui.badge variant="{{ $apiKey ? 'green' : 'yellow' }}">{{ $apiKey ? __('Connected') : __('No API key') }}</x-ui.badge>
            </div>

            <div class="space-y-4">
                <x-ui.form-group :label="__('Anthropic API Key')" for="apiKey" error="apiKey">
                    <div class="flex gap-2">
                        <x-ui.input wire:model="apiKey" id="apiKey" type="password" placeholder="sk-ant-api03-..." class="flex-1" />
                        <x-ui.button type="button" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection" variant="secondary" class="shrink-0">
                            <span wire:loading.remove wire:target="testConnection">{{ __('Test') }}</span>
                            <span wire:loading wire:target="testConnection">{{ __('Testing...') }}</span>
                        </x-ui.button>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">{{ __('Get your API key from console.anthropic.com. The key is stored encrypted.') }}</p>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Model')" for="model" error="model">
                    <x-ui.select wire:model="model" id="model">
                        <option value="claude-sonnet-4-20250514">Claude Sonnet 4 ({{ __('Recommended — fast & cost-effective') }})</option>
                        <option value="claude-opus-4-20250514">Claude Opus 4 ({{ __('Most capable — higher cost') }})</option>
                        <option value="claude-haiku-4-5-20251001">Claude Haiku 4.5 ({{ __('Fastest — lowest cost') }})</option>
                    </x-ui.select>
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Response Behavior --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 shadow-sm ring-1 ring-blue-200">
                        <x-icons.sliders class="h-5 w-5 text-blue-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Response Behavior') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Playbook and AI routing configuration') }}</p>
                    </div>
                </div>
                <x-ui.badge variant="blue">{{ $playbookFirst ? __('Playbooks → AI') : __('AI Direct') }}</x-ui.badge>
            </div>

            <div class="space-y-4">
                <x-ui.form-group :label="__('Try Playbooks First')" for="playbookFirst" error="playbookFirst">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" wire:model="playbookFirst" id="playbookFirst" class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                        <span class="ms-3 text-sm text-gray-600">{{ __('Use deterministic playbooks before invoking AI') }}</span>
                    </label>
                </x-ui.form-group>

                <x-ui.form-group :label="__('AI Fallback')" for="aiFallback" error="aiFallback">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" wire:model="aiFallback" id="aiFallback" class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                        <span class="ms-3 text-sm text-gray-600">{{ __('Fall back to AI when playbooks cannot resolve the issue') }}</span>
                    </label>
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Safety Guardrails --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-50 shadow-sm ring-1 ring-amber-200">
                        <x-icons.shield class="h-5 w-5 text-amber-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Safety Guardrails') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Limits to prevent the system from making things worse.') }}</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.form-group :label="__('Max Actions Per Incident')" for="maxActionsPerIncident" error="maxActionsPerIncident">
                    <x-ui.input wire:model="maxActionsPerIncident" id="maxActionsPerIncident" type="number" min="1" max="50" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('Maximum number of actions (cache flush, plugin deactivate, etc.) per incident.') }}</p>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Max AI Calls Per Incident')" for="maxAiCallsPerIncident" error="maxAiCallsPerIncident">
                    <x-ui.input wire:model="maxAiCallsPerIncident" id="maxAiCallsPerIncident" type="number" min="1" max="20" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('Maximum Claude API calls per incident before escalating.') }}</p>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Cooldown (minutes)')" for="cooldownMinutes" error="cooldownMinutes">
                    <x-ui.input wire:model="cooldownMinutes" id="cooldownMinutes" type="number" min="5" max="1440" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('Minimum time between responses for the same site and trigger type.') }}</p>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Max Incidents Per Site/Hour')" for="maxIncidentsPerSitePerHour" error="maxIncidentsPerSitePerHour">
                    <x-ui.input wire:model="maxIncidentsPerSitePerHour" id="maxIncidentsPerSitePerHour" type="number" min="1" max="20" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('Rate limit: maximum incident responses per site per hour.') }}</p>
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Save --}}
        <div class="flex items-center justify-end">
            <x-ui.button type="submit">
                {{ __('Save Settings') }}
            </x-ui.button>
        </div>
    </form>
</div>
