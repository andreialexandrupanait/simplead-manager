<div>
    @php
        // One description of each integration, rendered by one loop. This was
        // eight near-identical 35-line cards laid out in a 4-column grid — the
        // only settings page in the app that used a grid, so switching to this
        // tab changed the whole layout paradigm.
        $ai = [
            ['name' => 'anthropic', 'label' => 'Anthropic Claude', 'modal' => 'configure-anthropic',
             'about' => __('AI content generation'),
             'on' => (bool) $anthropicApiKey, 'detail' => __('Claude Sonnet, Opus, Haiku')],
            ['name' => 'openai', 'label' => 'OpenAI', 'modal' => 'configure-openai-ai',
             'about' => __('ChatGPT content generation'),
             'on' => (bool) $openAiApiKey, 'detail' => __('API key required')],
        ];

        $services = [
            ['name' => 'openapi', 'label' => 'OpenAPI.ro', 'modal' => 'configure-openapi',
             'about' => __('Company data from ANAF, by VAT number'),
             'on' => (bool) $openApiKey, 'detail' => __('Auto-fills client details')],
            ['name' => 'google', 'label' => 'Google', 'modal' => 'configure-google',
             'about' => __('Analytics and Search Console'),
             'on' => $connections->isNotEmpty() && $googleClientId && $googleClientSecret,
             'detail' => trans_choice(':n account connected|:n accounts connected', $connections->count(), ['n' => $connections->count()])],
            ['name' => 'cloudflare', 'label' => 'Cloudflare', 'modal' => 'configure-cloudflare',
             'about' => __('DNS, cache and analytics'),
             'on' => $this->cloudflareConnections->isNotEmpty(),
             'detail' => trans_choice(':n connection|:n connections', $this->cloudflareConnections->count(), ['n' => $this->cloudflareConnections->count()])],
            ['name' => 'postmark', 'label' => 'Postmark', 'modal' => 'configure-postmark',
             'about' => __('Transactional email delivery'),
             'on' => $this->postmarkConfigured, 'detail' => __('Account token')],
            ['name' => 'dropbox', 'label' => 'Dropbox', 'modal' => 'configure-dropbox',
             'about' => __('Storage for backups and reports'),
             'on' => $dropboxAppKey && $dropboxAppSecret, 'detail' => __('App key and secret')],
            ['name' => 'unsplash', 'label' => 'Unsplash', 'modal' => 'configure-unsplash',
             'about' => __('Images for reports'),
             'on' => (bool) $unsplashAccessKey, 'detail' => __('Access key')],
        ];
    @endphp

    <div class="space-y-6">
        <x-settings.section id="ai" :title="__('AI providers')"
                            :subtitle="__('The models used to generate content.')">
            <x-settings.integration-list :items="$ai" />
        </x-settings.section>

        <x-settings.section id="services" :title="__('Services')"
                            :subtitle="__('The external accounts the application depends on.')">
            <x-settings.integration-list :items="$services" />
        </x-settings.section>
    </div>

    {{-- ===== Configuration Modals ===== --}}

    {{-- Google Configuration Modal --}}
    <x-ui.modal name="configure-google" maxWidth="xl">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Google &mdash; {{ __('Settings') }}</h2>

        {{-- Connected accounts --}}
        <div class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <h4 class="text-sm font-medium text-gray-900">{{ __('Connected accounts') }}</h4>
                <button wire:click="addAccount"
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-300 bg-white text-gray-700 shadow-sm transition hover:bg-gray-50">
                    <svg aria-hidden="true" class="w-4 h-4" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                    {{ __('Add account') }}
                </button>
            </div>

            @if($connections->isEmpty())
                <p class="text-sm text-gray-500">{{ __('No Google accounts connected.') }}</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($connections as $conn)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                @if($conn->avatar_url)
                                    <img src="{{ $conn->avatar_url }}" alt="Avatar {{ $conn->name ?? '' }}" loading="lazy" class="h-8 w-8 rounded-full">
                                @else
                                    <div class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center bg-red-100 text-red-600">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                    </div>
                                @endif
                                <div>
                                    <div class="text-sm font-medium text-gray-900">{{ $conn->email }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ __('Connected') }} {{ $conn->created_at->format('d M Y') }}
                                        @if($conn->sites_using > 0)
                                            &middot; {{ trans_choice('Used by :count site|Used by :count sites', $conn->sites_using, ['count' => $conn->sites_using]) }}
                                        @endif
                                    </div>
                                    @if($conn->scopes)
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach($conn->scopes as $scope)
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                                    {{ str_replace('.readonly', '', $scope) === 'analytics' ? 'Analytics' : 'Search Console' }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <button wire:click="confirmDisconnect({{ $conn->id }})"
                                    class="rounded p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50"
                                    title="{{ __('Disconnect') }}">
                                <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- API Credentials --}}
        <div class="border-t border-gray-200 pt-5">
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-sm font-medium text-gray-900">{{ __('API Credentials') }}</h4>
                @if($googleClientId && $googleClientSecret)
                    <x-ui.badge variant="green">{{ __('Configured') }}</x-ui.badge>
                @else
                    <x-ui.badge variant="red">{{ __('Not configured') }}</x-ui.badge>
                @endif
            </div>

            <form wire:submit="saveGoogleCredentials" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client ID</label>
                    <x-ui.input type="text" wire:model="googleClientId" placeholder="{{ __('Enter Google Client ID') }}" />
                    @error('googleClientId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client Secret</label>
                    <x-ui.input type="password" wire:model="googleClientSecret" placeholder="{{ __('Enter Google Client Secret') }}" />
                    @error('googleClientSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled" size="sm">
                        {{ __('Save') }}
                    </x-ui.button>
                </div>
            </form>

            <div x-data="{ showInstructions: false }" class="mt-4">
                <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                    <svg aria-hidden="true" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    {{ __('How to obtain Google API credentials') }}
                </button>
                <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                    <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                        <li>Go to the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="font-medium underline hover:text-blue-900">Google Cloud Console &rarr; Credentials</a></li>
                        <li>Create or select an <strong>OAuth 2.0 Client ID</strong> (type: Web application)</li>
                        <li>Under <strong>Authorized redirect URIs</strong>, add: <code class="bg-blue-100 px-1 rounded text-xs">{{ route('google.callback') }}</code></li>
                        <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> and paste them below</li>
                    </ol>
                    <p class="mt-2 text-xs text-blue-600">Make sure the <strong>Google Analytics API</strong> and <strong>Google Search Console API</strong> are enabled in your project.</p>
                </div>
            </div>
        </div>
    </x-ui.modal>

    {{-- Cloudflare Configuration Modal --}}
    <x-ui.modal name="configure-cloudflare" maxWidth="xl">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Cloudflare &mdash; {{ __('Settings') }}</h2>

        {{-- Existing connections --}}
        @if($this->cloudflareConnections->isNotEmpty())
            <div class="mb-6">
                <h4 class="text-sm font-medium text-gray-900 mb-3">{{ __('Active connections') }}</h4>
                <div class="divide-y divide-gray-100">
                    @foreach($this->cloudflareConnections as $cfConn)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <div class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center bg-orange-100 text-orange-600">
                                    <x-icons.cloud class="w-4 h-4" />
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ $cfConn->account_email ?: __('Connection #') . $cfConn->id }}
                                        <x-ui.badge :variant="$cfConn->is_valid ? 'green' : 'red'" class="ml-1">{{ $cfConn->is_valid ? __('Valid') : __('Invalid') }}</x-ui.badge>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ trans_choice(':count zone connected|:count zones connected', $cfConn->siteCloudflare->count(), ['count' => $cfConn->siteCloudflare->count()]) }}
                                        @if($cfConn->last_validated_at)
                                            &middot; {{ __('Tested') }} {{ $cfConn->last_validated_at->diffForHumans() }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="testCloudflareConnection({{ $cfConn->id }})"
                                    wire:loading.attr="disabled" wire:target="testCloudflareConnection({{ $cfConn->id }})"
                                    class="rounded p-1.5 text-gray-400 transition hover:bg-accent-50 hover:text-accent-600 disabled:opacity-50"
                                    title="{{ __('Test connection') }}">
                                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden"
                                                  wire:target="testCloudflareConnection({{ $cfConn->id }})" />
                                    <x-icons.check-circle class="w-4 h-4" wire:loading.class="hidden"
                                                          wire:target="testCloudflareConnection({{ $cfConn->id }})" />
                                </button>
                                <button wire:click="confirmDeleteCloudflare({{ $cfConn->id }})"
                                    class="rounded p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50"
                                    title="{{ __('Delete') }}">
                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Add connection --}}
        <div class="{{ $this->cloudflareConnections->isNotEmpty() ? 'border-t border-gray-200 pt-5' : '' }}">
            <h4 class="text-sm font-medium text-gray-900 mb-4">{{ __('Add connection') }}</h4>
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    {{-- This field had no @error: submit an empty or short token
                         and the button silently did nothing. --}}
                    <x-ui.form-group :label="__('API token')" for="cfApiToken" error="cfApiToken">
                        <x-ui.input type="password" id="cfApiToken" wire:model="cfApiToken"
                                    placeholder="{{ __('Enter the Cloudflare token') }}" />
                    </x-ui.form-group>
                </div>
                <x-ui.button wire:click="addCloudflareConnection" wire:loading.attr="disabled"
                             wire:target="addCloudflareConnection">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden"
                                  wire:target="addCloudflareConnection" />
                    {{ __('Connect') }}
                </x-ui.button>
            </div>

            <div x-data="{ showInstructions: false }" class="mt-4">
                <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                    <svg aria-hidden="true" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    {{ __('How to obtain a Cloudflare API token') }}
                </button>
                <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                    <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                        <li>Log in to your <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" class="font-medium underline hover:text-blue-900">Cloudflare dashboard</a> and go to <strong>My Profile &rarr; API Tokens</strong></li>
                        <li>Click <strong>Create Token</strong></li>
                        <li>Use the <strong>"Edit zone DNS"</strong> template, or create a custom token with these permissions:
                            <ul class="ml-5 mt-1 list-disc text-xs text-blue-700 space-y-0.5">
                                <li><strong>Zone &rarr; Zone &rarr; Read</strong> (list and view zones)</li>
                                <li><strong>Zone &rarr; DNS &rarr; Edit</strong> (manage DNS records)</li>
                                <li><strong>Zone &rarr; Zone Settings &rarr; Read</strong> (SSL, security level, WAF)</li>
                                <li><strong>Zone &rarr; Firewall Services &rarr; Edit</strong> (firewall rules, access rules)</li>
                                <li><strong>Zone &rarr; Cache Purge &rarr; Purge</strong> (cache management)</li>
                                <li><strong>Zone &rarr; Analytics &rarr; Read</strong> (zone analytics)</li>
                            </ul>
                        </li>
                        <li>Under <strong>Zone Resources</strong>, select the zones you want to manage (or "All zones")</li>
                        <li>Click <strong>Continue to summary</strong> &rarr; <strong>Create Token</strong></li>
                        <li>Copy the token and paste it above</li>
                    </ol>
                </div>
            </div>
        </div>
    </x-ui.modal>

    {{-- Dropbox Configuration Modal --}}
    <x-ui.modal name="configure-dropbox" maxWidth="lg">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Dropbox &mdash; {{ __('API Credentials') }}</h2>

        <form wire:submit="saveDropboxCredentials" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">App Key</label>
                <x-ui.input type="text" wire:model="dropboxAppKey" placeholder="{{ __('Enter Dropbox App Key') }}" />
                @error('dropboxAppKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">App Secret</label>
                <x-ui.input type="password" wire:model="dropboxAppSecret" placeholder="{{ __('Enter Dropbox App Secret') }}" />
                @error('dropboxAppSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" size="sm">
                    {{ __('Save') }}
                </x-ui.button>
            </div>
        </form>

        <div x-data="{ showInstructions: false }" class="mt-4">
            <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                <svg aria-hidden="true" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                {{ __('How to obtain Dropbox API credentials') }}
            </button>
            <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                    <li>Go to the <a href="https://www.dropbox.com/developers/apps" target="_blank" class="font-medium underline hover:text-blue-900">Dropbox App Console</a></li>
                    <li>Click <strong>Create app</strong> (or select an existing one)</li>
                    <li>Choose <strong>Scoped access</strong> and <strong>Full Dropbox</strong> access type</li>
                    <li>Under the <strong>Permissions</strong> tab, enable: <code class="bg-blue-100 px-1 rounded text-xs">account_info.read</code>, <code class="bg-blue-100 px-1 rounded text-xs">files.metadata.read</code>, <code class="bg-blue-100 px-1 rounded text-xs">files.content.read</code>, <code class="bg-blue-100 px-1 rounded text-xs">files.content.write</code></li>
                    <li>Under the <strong>Settings</strong> tab, add this <strong>Redirect URI</strong>: <code class="bg-blue-100 px-1 rounded text-xs">{{ route('dropbox.callback') }}</code></li>
                    <li>Copy the <strong>App key</strong> and <strong>App secret</strong> from the Settings tab and paste them above</li>
                </ol>
            </div>
        </div>
    </x-ui.modal>

    {{-- Unsplash Configuration Modal --}}
    <x-ui.modal name="configure-unsplash" maxWidth="lg">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Unsplash &mdash; {{ __('Settings') }}</h2>

        <p class="text-sm text-gray-500 mb-4">{{ __('Provides background images for the login page slideshow.') }}</p>

        <form wire:submit="saveUnsplashCredentials" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Access Key</label>
                <x-ui.input type="text" wire:model="unsplashAccessKey" placeholder="{{ __('Enter Unsplash Access Key') }}" />
                @error('unsplashAccessKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" size="sm">
                    {{ __('Save') }}
                </x-ui.button>
            </div>
        </form>

        <div x-data="{ showInstructions: false }" class="mt-4">
            <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                <svg aria-hidden="true" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                {{ __('How to obtain Unsplash credentials') }}
            </button>
            <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                    <li>Go to the <a href="https://unsplash.com/developers" target="_blank" class="font-medium underline hover:text-blue-900">Unsplash Developers</a> page and sign in</li>
                    <li>Click <strong>Your apps</strong> &rarr; <strong>New Application</strong></li>
                    <li>Accept the API guidelines and create the app</li>
                    <li>Copy the <strong>Access Key</strong> from the app's page and paste it above</li>
                </ol>
                <p class="mt-2 text-xs text-blue-600">Free tier: max 50 requests/hour — sufficient for the login slideshow.</p>
            </div>
        </div>
    </x-ui.modal>

    {{-- OpenAPI.ro Configuration Modal --}}
    <x-ui.modal name="configure-openapi" maxWidth="lg">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">OpenAPI.ro &mdash; {{ __('Settings') }}</h2>

        <p class="text-sm text-gray-500 mb-4">{{ __('Enables automatic company data lookup by CUI (Romanian tax ID) when creating or editing clients.') }}</p>

        <form wire:submit="saveOpenApiCredentials" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                <x-ui.input type="password" wire:model="openApiKey" placeholder="{{ __('Enter OpenAPI.ro API Key') }}" />
                @error('openApiKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center justify-between">
                <button type="button" wire:click="testOpenApiConnection" wire:loading.attr="disabled" wire:target="testOpenApiConnection"
                        class="text-sm text-gray-500 hover:text-gray-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="testOpenApiConnection">{{ __('Test Connection') }}</span>
                    <span wire:loading wire:target="testOpenApiConnection">{{ __('Testing...') }}</span>
                </button>
                <x-ui.button type="submit" wire:loading.attr="disabled" size="sm">
                    {{ __('Save') }}
                </x-ui.button>
            </div>
        </form>

        <div x-data="{ showInstructions: false }" class="mt-4">
            <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                <svg aria-hidden="true" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                {{ __('How to obtain OpenAPI.ro credentials') }}
            </button>
            <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                    <li>{{ __('Go to') }} <a href="https://openapi.ro/ro/users/sign_up" target="_blank" class="font-medium underline hover:text-blue-900">openapi.ro</a> {{ __('and create a free account') }}</li>
                    <li>{{ __('Confirm your email address') }}</li>
                    <li>{{ __('Generate an API key from your dashboard') }}</li>
                    <li>{{ __('Paste the API key above and click Save') }}</li>
                </ol>
                <p class="mt-2 text-xs text-blue-600">{{ __('Free tier: 100 requests/month — sufficient for manual client lookups.') }}</p>
            </div>
        </div>
    </x-ui.modal>

    {{-- Disconnect Google confirmation modal --}}
    <x-ui.modal name="disconnect-google">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Disconnect Google account') }}</h2>
        <p class="mt-2 text-sm text-gray-600">
            {{ __('Are you sure you want to disconnect this Google account? All associated Analytics and Search Console connections will be removed.') }}
        </p>

        <div class="mt-4 flex justify-end gap-2">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal-disconnect-google')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" wire:click="disconnectAccount" wire:loading.attr="disabled" wire:target="disconnectAccount">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="disconnectAccount" />
                {{ __('Disconnect') }}
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Delete Cloudflare connection confirmation modal --}}
    <x-ui.modal name="delete-cloudflare">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Delete Cloudflare connection') }}</h2>
        <p class="mt-2 text-sm text-gray-600">
            {{ __('Are you sure you want to delete this Cloudflare connection? All sites linked to this connection will be disconnected.') }}
        </p>

        <div class="mt-4 flex justify-end gap-2">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal-delete-cloudflare')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="danger" wire:click="deleteCloudflareConnection" wire:loading.attr="disabled" wire:target="deleteCloudflareConnection">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="deleteCloudflareConnection" />
                {{ __('Delete') }}
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Anthropic Claude Configuration Modal --}}
    <x-ui.modal name="configure-anthropic" maxWidth="lg">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Anthropic Claude &mdash; {{ __('Settings') }}</h2>

        <p class="text-sm text-gray-500 mb-4">{{ __('Used for AI content generation (Content AI). Supports Claude Sonnet, Opus, and Haiku models.') }}</p>

        <form wire:submit="saveAnthropicCredentials" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                <x-ui.input type="password" wire:model="anthropicApiKey" placeholder="sk-ant-api03-..." />
                @error('anthropicApiKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center justify-between">
                <button type="button" wire:click="testAnthropicConnection" wire:loading.attr="disabled" wire:target="testAnthropicConnection"
                        class="text-sm text-gray-500 hover:text-gray-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="testAnthropicConnection">{{ __('Test Connection') }}</span>
                    <span wire:loading wire:target="testAnthropicConnection">{{ __('Testing...') }}</span>
                </button>
                <x-ui.button type="submit" wire:loading.attr="disabled" size="sm">
                    {{ __('Save') }}
                </x-ui.button>
            </div>
        </form>

        <div x-data="{ showInstructions: false }" class="mt-4">
            <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                <svg aria-hidden="true" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                {{ __('How to obtain an Anthropic API key') }}
            </button>
            <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                    <li>{{ __('Go to') }} <a href="https://console.anthropic.com/" target="_blank" class="font-medium underline hover:text-blue-900">console.anthropic.com</a></li>
                    <li>{{ __('Create an account or sign in') }}</li>
                    <li>{{ __('Navigate to API Keys and create a new key') }}</li>
                    <li>{{ __('Paste the API key above and click Save') }}</li>
                </ol>
            </div>
        </div>
    </x-ui.modal>

    {{-- OpenAI Configuration Modal --}}
    <x-ui.modal name="configure-openai-ai" maxWidth="lg">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">OpenAI &mdash; {{ __('Settings') }}</h2>

        <p class="text-sm text-gray-500 mb-4">{{ __('Used for AI content generation (Content AI). Supports GPT-4o, GPT-4o mini, and other models.') }}</p>

        <form wire:submit="saveOpenAiCredentials" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                <x-ui.input type="password" wire:model="openAiApiKey" placeholder="sk-..." />
                @error('openAiApiKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center justify-between">
                <button type="button" wire:click="testOpenAiConnection" wire:loading.attr="disabled" wire:target="testOpenAiConnection"
                        class="text-sm text-gray-500 hover:text-gray-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="testOpenAiConnection">{{ __('Test Connection') }}</span>
                    <span wire:loading wire:target="testOpenAiConnection">{{ __('Testing...') }}</span>
                </button>
                <x-ui.button type="submit" wire:loading.attr="disabled" size="sm">
                    {{ __('Save') }}
                </x-ui.button>
            </div>
        </form>

        <div x-data="{ showInstructions: false }" class="mt-4">
            <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                <svg aria-hidden="true" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                {{ __('How to obtain an OpenAI API key') }}
            </button>
            <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                    <li>{{ __('Go to') }} <a href="https://platform.openai.com/api-keys" target="_blank" class="font-medium underline hover:text-blue-900">platform.openai.com</a></li>
                    <li>{{ __('Create an account or sign in') }}</li>
                    <li>{{ __('Create a new API key') }}</li>
                    <li>{{ __('Paste the API key above and click Save') }}</li>
                </ol>
            </div>
        </div>
    </x-ui.modal>

    {{-- Postmark Configuration Modal --}}
    <x-ui.modal name="configure-postmark" maxWidth="lg">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Postmark &mdash; {{ __('Settings') }}</h2>

        <p class="text-sm text-gray-500 mb-4">{{ __('Used to auto-discover DKIM selectors for domains hosted on Postmark. Account-level token only — Server tokens do not have access to domain settings.') }}</p>

        <form wire:submit="savePostmarkToken" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Account Token') }}</label>
                <x-ui.input type="password" wire:model="postmarkAccountToken" placeholder="{{ __('Enter Postmark Account Token') }}" />
                @error('postmarkAccountToken') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center justify-between">
                @if($this->postmarkConfigured)
                    <button type="button" wire:click="disconnectPostmark"
                            wire:confirm="{{ __('Disconnect Postmark and clear cached DKIM data?') }}"
                            wire:loading.attr="disabled" wire:target="disconnectPostmark"
                            class="text-sm text-red-500 transition hover:text-red-700 disabled:opacity-50">
                        {{ __('Disconnect') }}
                    </button>
                @else
                    <span></span>
                @endif
                <x-ui.button type="submit" wire:loading.attr="disabled" size="sm">
                    <span wire:loading.remove wire:target="savePostmarkToken">{{ __('Save & Test') }}</span>
                    <span wire:loading wire:target="savePostmarkToken">{{ __('Testing...') }}</span>
                </x-ui.button>
            </div>
        </form>

        <div x-data="{ showInstructions: false }" class="mt-4">
            <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                <svg aria-hidden="true" class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                {{ __('How to obtain a Postmark Account Token') }}
            </button>
            <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                    <li>{{ __('Go to') }} <a href="https://account.postmarkapp.com/api_tokens" target="_blank" class="font-medium underline hover:text-blue-900">account.postmarkapp.com/api_tokens</a></li>
                    <li>{{ __('Click "API Tokens" tab (top right user menu → API Tokens)') }}</li>
                    <li>{{ __('Copy the Account Token (NOT a Server Token)') }}</li>
                    <li>{{ __('Paste above and click Save & Test') }}</li>
                </ol>
            </div>
        </div>
    </x-ui.modal>
</div>

