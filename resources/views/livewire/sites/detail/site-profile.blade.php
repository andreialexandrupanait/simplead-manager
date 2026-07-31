<div class="space-y-4">
    {{-- Header --}}
    <div>
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Profile') }}</h2>
        <p class="mt-1 text-sm text-gray-500">
            {{ __('The settings that cannot be shared with another site. Everything shareable lives on the maintenance plan.') }}
        </p>
    </div>

    {{-- Key URLs --}}
    <x-ui.card>
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-gray-900">{{ __('Key URLs') }}</p>
                <p class="mt-1 max-w-2xl text-sm text-gray-500">
                    {{ __('The pages an update is judged against: homepage, the top pages from Search Console, the contact page and — on a shop — a product page. Derived automatically and recalculated quarterly, unless you pin them.') }}
                </p>
            </div>
            <div class="shrink-0">
                @if($site->key_urls_locked_at)
                    <x-ui.badge variant="blue">{{ __('Pinned') }}</x-ui.badge>
                @else
                    <x-ui.badge variant="gray">{{ __('Automatic') }}</x-ui.badge>
                @endif
            </div>
        </div>

        <div class="mt-4">
            <textarea wire:model="keyUrls" rows="5"
                      class="w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-xs text-gray-800 focus:border-accent-500 focus:outline-none focus:ring-1 focus:ring-accent-500"
                      placeholder="https://example.com/"></textarea>
            <p class="mt-1 text-xs text-gray-400">{{ __('One URL per line.') }}</p>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <x-ui.button wire:click="saveKeyUrls">{{ __('Pin these URLs') }}</x-ui.button>
            @if($site->key_urls_locked_at)
                <x-ui.button variant="secondary" wire:click="unlockKeyUrls">
                    {{ __('Back to automatic') }}
                </x-ui.button>
                <span class="text-xs text-gray-400">
                    {{ __('Pinned') }} {{ $site->key_urls_locked_at->diffForHumans() }}
                </span>
            @elseif($site->key_urls_derived_at)
                <span class="text-xs text-gray-400">
                    {{ __('Last recalculated') }} {{ $site->key_urls_derived_at->diffForHumans() }}
                </span>
            @endif
        </div>

        @if($site->key_urls_locked_at && $this->derivedPreview)
            <div class="mt-4 border-t pt-3">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('What automatic detection would pick') }}</p>
                <ul class="mt-1.5 space-y-0.5">
                    @foreach($this->derivedPreview as $url)
                        <li class="truncate font-mono text-xs text-gray-500">{{ $url }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-ui.card>

    {{-- Canary selector + form test address --}}
    <x-ui.card>
        <p class="text-sm font-medium text-gray-900">{{ __('Smoke check and form test') }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm text-gray-700">{{ __('Canary selector') }}</label>
                <input type="text" wire:model="canarySelector"
                       class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-xs text-gray-800 focus:border-accent-500 focus:outline-none focus:ring-1 focus:ring-accent-500"
                       placeholder="footer.site-footer">
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('A fragment that must appear on a healthy page — usually something from the footer, since a page that rendered its footer rendered everything above it. Empty falls back to looking for a body tag, which proves much less.') }}
                </p>
                @error('canarySelector') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm text-gray-700">{{ __('Form test address') }}</label>
                <input type="email" wire:model="formTestEmail"
                       class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-800 focus:border-accent-500 focus:outline-none focus:ring-1 focus:ring-accent-500"
                       placeholder="{{ config('monitoring.form_test.deliver_to') ?: 'nobody@example.com' }}">
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('Where this site\'s form notification is redirected during the weekly test. Empty uses the global address.') }}
                </p>
                @error('formTestEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-3">
            <x-ui.button wire:click="saveSmokeSettings">{{ __('Save') }}</x-ui.button>
        </div>
    </x-ui.card>

    {{-- Risk list --}}
    <x-ui.card>
        <p class="text-sm font-medium text-gray-900">{{ __('Risk list') }}</p>
        <p class="mt-1 max-w-2xl text-sm text-gray-500">
            {{ __('Plugins that never update without approval on this site. Populated automatically from the plugin category and this site\'s history of failed updates; entries you add by hand are never overwritten.') }}
        </p>

        <div class="mt-4 flex flex-wrap items-end gap-2">
            <div class="min-w-40 flex-1">
                <label class="block text-xs text-gray-500">{{ __('Plugin slug') }}</label>
                <input type="text" wire:model="newRiskySlug"
                       class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 font-mono text-xs text-gray-800 focus:border-accent-500 focus:outline-none focus:ring-1 focus:ring-accent-500"
                       placeholder="elementor">
            </div>
            <div class="min-w-40 flex-[2]">
                <label class="block text-xs text-gray-500">{{ __('Why') }}</label>
                <input type="text" wire:model="newRiskyReason"
                       class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-800 focus:border-accent-500 focus:outline-none focus:ring-1 focus:ring-accent-500"
                       placeholder="{{ __('Broke the checkout in March') }}">
            </div>
            <x-ui.button wire:click="addRiskyPlugin">{{ __('Add') }}</x-ui.button>
        </div>
        @error('newRiskySlug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

        @if($this->riskyPlugins->isEmpty())
            <div class="mt-4">
                <x-ui.empty-state
                    :title="__('Nothing on the risk list')"
                    :description="__('Every plugin on this site follows the normal update rules.')" />
            </div>
        @else
            <div class="mt-4 divide-y">
                @foreach($this->riskyPlugins as $risky)
                    <div class="flex items-center justify-between gap-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-sm text-gray-900">{{ $risky->slug }}</span>
                                <x-ui.badge :variant="$risky->source === 'manual' ? 'blue' : 'gray'">
                                    {{ $risky->source === 'manual' ? __('By hand') : __('Automatic') }}
                                </x-ui.badge>
                            </div>
                            @if($risky->reason)
                                <p class="mt-0.5 truncate text-xs text-gray-500">{{ $risky->reason }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="removeRiskyPlugin({{ $risky->id }})"
                                class="shrink-0 text-xs text-gray-400 hover:text-red-600">
                            {{ __('Remove') }}
                        </button>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.card>
</div>
