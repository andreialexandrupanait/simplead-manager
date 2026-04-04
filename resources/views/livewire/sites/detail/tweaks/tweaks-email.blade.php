<div>
    <x-ui.page-header title="{{ __('Email') }}" subtitle="{{ __('WordPress email configuration and delivery settings') }}">
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" x-on:click="$dispatch('open-modal-copy-settings')">
                {{ __('Copy to Sites') }}
            </x-ui.button>
            <x-ui.button variant="ghost" size="sm" wire:click="verifySettings" wire:loading.attr="disabled" wire:target="verifySettings">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="verifySettings" />
                {{ __('Verify') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.sites.detail.tweaks.partials.tweaks-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="verify-error" />

    {{-- Custom Email From --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Sender Identity') }}</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Custom Email From') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Override the default WordPress "From" name and email address.') }}</p>
                    @if($toggles['custom_email_from'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['custom_email_from'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['custom_email_from'] ?? false"
                    wire:click="toggleSetting('custom_email_from')"
                />
            </div>

            @if($toggles['custom_email_from'] ?? false)
                <div class="ml-4 space-y-3 border-l-2 border-purple-100 pl-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('From Name') }}</label>
                        <input type="text" wire:model.live="emailFromName" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500 max-w-md" placeholder="{{ __('My Website') }}" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('From Email') }}</label>
                        <input type="email" wire:model.live="emailFromAddress" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500 max-w-md" placeholder="noreply@example.com" />
                    </div>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Postmark --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Postmark SMTP') }}</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Enable Postmark') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Route this site\'s emails through Postmark for reliable delivery.') }}</p>
                    @if($toggles['postmark_config'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['postmark_config'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['postmark_config'] ?? false"
                    wire:click="toggleSetting('postmark_config')"
                />
            </div>

            @if($toggles['postmark_config'] ?? false)
                <div class="ml-4 space-y-3 border-l-2 border-purple-100 pl-4">
                    {{-- Global token status --}}
                    @if($this->hasGlobalPostmarkToken)
                        <x-ui.alert variant="success">
                            {{ __('Using global Postmark token from') }}
                            <a href="{{ route('settings.integrations') }}" class="font-medium underline">{{ __('Settings → Integrations') }}</a>.
                        </x-ui.alert>
                    @else
                        <x-ui.alert variant="warning">
                            {{ __('No global Postmark token configured.') }}
                            <a href="{{ route('settings.integrations') }}" class="font-medium underline">{{ __('Configure in Settings → Integrations') }}</a>
                            {{ __('or enter a per-site token below.') }}
                        </x-ui.alert>
                    @endif

                    {{-- Per-site override --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Per-Site Token Override') }} <span class="text-gray-400">({{ __('optional') }})</span></label>
                        <input type="password" wire:model.live="postmarkOverrideToken" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500 max-w-md" placeholder="{{ __('Leave empty to use global token') }}" />
                        <p class="mt-1 text-xs text-gray-400">{{ __('Only set this if this site uses a different Postmark server than the global one.') }}</p>
                    </div>

                    {{-- Message stream --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Message Stream') }}</label>
                        <select wire:model.live="postmarkMessageStream" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500 max-w-xs">
                            <option value="outbound">{{ __('Transactional (outbound)') }}</option>
                            <option value="broadcast">{{ __('Broadcast') }}</option>
                        </select>
                    </div>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Email Logging --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Email Logging') }}</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ __('Log Outgoing Emails') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Keep a log of the last 100 emails sent by WordPress with delivery status.') }}</p>
                        @if($toggles['email_logging'] ?? false)
                            <x-security.setting-status :status="$settingStatuses['email_logging'] ?? null" />
                        @endif
                    </div>
                </div>
                <x-ui.toggle
                    :enabled="$toggles['email_logging'] ?? false"
                    wire:click="toggleSetting('email_logging')"
                />
            </div>
        </div>
    </x-ui.card>

    {{-- Send Test Email --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Send Test Email') }}</h3>

        <x-ui.flash-alert type="success" key="test-success" />
        <x-ui.flash-alert type="error" key="test-error" />

        <div class="flex items-end gap-3">
            <div class="flex-1 max-w-md">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Recipient') }}</label>
                <input type="email" wire:model="testEmailTo" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500" placeholder="test@example.com" />
            </div>
            <x-ui.button wire:click="sendTestEmail" wire:loading.attr="disabled" wire:target="sendTestEmail" size="sm">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="sendTestEmail" />
                {{ __('Send Test') }}
            </x-ui.button>
        </div>
        <p class="mt-2 text-xs text-gray-400">{{ __('Sends a test email using the current email configuration (Postmark + From settings).') }}</p>
    </x-ui.card>

    {{-- Email Log --}}
    <x-ui.card>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold text-gray-900">{{ __('Email Log') }}</h3>
            <x-ui.button variant="ghost" size="sm" wire:click="fetchEmailLog" wire:loading.attr="disabled" wire:target="fetchEmailLog">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="fetchEmailLog" />
                {{ $logLoaded ? __('Refresh') : __('Load Log') }}
            </x-ui.button>
        </div>

        <x-ui.flash-alert type="error" key="log-error" />

        @if(!$logLoaded)
            <p class="text-sm text-gray-500">{{ __('Click "Load Log" to fetch email log from WordPress.') }}</p>
        @elseif(empty($emailLog))
            <p class="text-sm text-gray-500">{{ __('No emails logged yet. Enable Email Logging above and send a test email.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                            <th class="px-3 py-2">{{ __('Status') }}</th>
                            <th class="px-3 py-2">{{ __('To') }}</th>
                            <th class="px-3 py-2">{{ __('Subject') }}</th>
                            <th class="px-3 py-2">{{ __('Time') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($emailLog as $i => $entry)
                            <tr class="hover:bg-gray-50" x-data="{ showDetails: false }">
                                <td class="px-3 py-2 whitespace-nowrap">
                                    @if(($entry['status'] ?? '') === 'sent')
                                        <span class="inline-flex items-center gap-1 text-green-700">
                                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            {{ __('Sent') }}
                                        </span>
                                    @elseif(($entry['status'] ?? '') === 'failed')
                                        <span class="inline-flex items-center gap-1 text-red-700">
                                            <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                            {{ __('Failed') }}
                                        </span>
                                    @else
                                        <span class="text-yellow-600">{{ __('Sending') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 max-w-[200px] truncate">{{ $entry['to'] ?? '' }}</td>
                                <td class="px-3 py-2 max-w-[250px] truncate">
                                    <button @click="showDetails = !showDetails" class="text-left hover:text-purple-600 truncate block w-full">
                                        {{ $entry['subject'] ?? '(no subject)' }}
                                    </button>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-gray-500">{{ $entry['timestamp'] ?? '' }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    @if(($entry['status'] ?? '') === 'failed')
                                        <button wire:click="resendEmail({{ $i }})" class="text-xs text-purple-600 hover:text-purple-700 font-medium">
                                            {{ __('Resend') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                            {{-- Expandable details row --}}
                            <tr x-show="showDetails" x-collapse x-cloak>
                                <td colspan="5" class="px-3 py-3 bg-gray-50">
                                    <div class="space-y-2 text-xs">
                                        @if(!empty($entry['error']))
                                            <div>
                                                <span class="font-medium text-red-700">{{ __('Error:') }}</span>
                                                <span class="text-red-600">{{ $entry['error'] }}</span>
                                            </div>
                                        @endif
                                        @if(!empty($entry['headers']))
                                            <div>
                                                <span class="font-medium text-gray-700">{{ __('Headers:') }}</span>
                                                <pre class="mt-1 text-gray-500 whitespace-pre-wrap font-mono text-[11px] bg-white rounded p-2 border">{{ $entry['headers'] }}</pre>
                                            </div>
                                        @endif
                                        @if(!empty($entry['body']))
                                            <div>
                                                <span class="font-medium text-gray-700">{{ __('Body preview:') }}</span>
                                                <pre class="mt-1 text-gray-500 whitespace-pre-wrap font-mono text-[11px] bg-white rounded p-2 border max-h-32 overflow-y-auto">{{ Str::limit($entry['body'], 500) }}</pre>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Sticky Save Bar --}}
    @if($isDirty)
        <div class="sticky bottom-0 mt-6 -mx-6 -mb-6 rounded-b-lg border-t border-gray-200 bg-white px-6 py-4 flex items-center justify-between shadow-lg">
            <p class="text-sm text-gray-500">{{ __('You have unsaved changes') }}</p>
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                {{ __('Save Changes') }}
            </x-ui.button>
        </div>
    @endif

    <livewire:components.copy-settings-modal :source-site="$site" :show-security-option="false" :show-tweaks-option="true" :show-modules-option="false" />
</div>
