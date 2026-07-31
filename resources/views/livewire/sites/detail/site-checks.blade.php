<div>
    {{-- Header --}}
    <div class="mb-6">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Checks') }}</h2>
        <p class="mt-1 text-sm text-gray-500">
            {{ __('Does the site actually work — not just whether it responds.') }}
        </p>
    </div>

    {{-- Tab switcher --}}
    <div class="mb-4 flex w-fit items-center gap-1 rounded-lg bg-gray-100 p-1">
        @php
            $tabs = [
                'forms' => __('Forms'),
                'woo' => __('WooCommerce'),
                'links' => __('Broken links'),
                'errors' => __('PHP errors'),
            ];
        @endphp
        @foreach($tabs as $key => $label)
            <button
                wire:click="setTab('{{ $key }}')"
                class="rounded-md px-4 py-2 text-sm font-medium transition {{ $tab === $key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"
            >
                {{ $label }}
                @if($key === 'errors' && $this->phpErrors->count())
                    <span class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs">{{ $this->phpErrors->count() }}</span>
                @elseif($key === 'links' && $this->linkRun?->broken_count)
                    <span class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs">{{ $this->linkRun->broken_count }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ─────────────────────────── Forms ─────────────────────────── --}}
    @if($tab === 'forms')
        @php $check = $this->formCheck; @endphp

        <x-ui.card>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">{{ __('Contact form submission test') }}</p>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500">
                        {{ __('A real submission is sent through the site\'s own form, marked as a test, with the site\'s integrations (CRM, Zapier, autoresponder, Mailchimp) suppressed for the duration. Only form plugins with a proven suppression path are ever submitted to.') }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <x-ui.button variant="secondary" wire:click="checkFormCapability" wire:loading.attr="disabled">
                        {{ __('Check plugin') }}
                    </x-ui.button>
                    <x-ui.button wire:click="runFormTest" wire:loading.attr="disabled"
                                 :disabled="! ($check?->supported ?? false)">
                        <x-ui.spinner size="sm" class="mr-1 hidden" wire:loading.class.remove="hidden" wire:target="runFormTest" />
                        {{ __('Run test now') }}
                    </x-ui.button>
                </div>
            </div>

            @if($formError)
                <div class="mt-4">
                    <x-ui.alert type="warning">{{ $formError }}</x-ui.alert>
                </div>
            @endif

            @if($check)
                <dl class="mt-5 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <x-ui.info-row :label="__('Form plugin')">
                        {{ $check->form_plugin ?? __('None detected') }}
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Supported')">
                        <x-ui.badge :variant="$check->supported ? 'green' : 'gray'">
                            {{ $check->supported ? __('Yes') : __('No') }}
                        </x-ui.badge>
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Last submitted')">
                        {{ $check->submitted_at?->diffForHumans() ?? __('Never') }}
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Integrations suppressed')">
                        <x-ui.badge :variant="$check->suppression_confirmed ? 'green' : 'gray'">
                            {{ $check->suppression_confirmed ? __('Confirmed') : __('Not confirmed') }}
                        </x-ui.badge>
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Delivery verified')">
                        <x-ui.badge :variant="$check->delivery_verified ? 'green' : 'gray'">
                            {{ $check->delivery_verified ? __('Yes') : __('Not yet') }}
                        </x-ui.badge>
                        @if($check->delivery_verified_at)
                            <span class="ml-1 text-xs text-gray-400">{{ $check->delivery_verified_at->diffForHumans() }}</span>
                        @endif
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Notification redirected to')">
                        {{ $this->formDeliveryAddress ?? __('Nowhere — delivery cannot be verified') }}
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Last checked')">
                        {{ $check->checked_at?->diffForHumans() ?? '—' }}
                    </x-ui.info-row>
                </dl>
            @else
                <div class="mt-5">
                    <x-ui.empty-state
                        :title="__('Never checked')"
                        :description="__('Check which form plugin this site uses to find out whether a test submission is safe here.')" />
                </div>
            @endif
        </x-ui.card>

    {{-- ──────────────────────── WooCommerce ──────────────────────── --}}
    @elseif($tab === 'woo')
        @php $woo = $this->wooCheck; @endphp

        <x-ui.card>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">{{ __('Store health') }}</p>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Checkout responds, payment gateways are enabled, no orders are stuck pending, scheduled-sale cron is registered.') }}
                    </p>
                </div>
                <x-ui.button variant="secondary" class="shrink-0" wire:click="runWooCheck">
                    {{ __('Check now') }}
                </x-ui.button>
            </div>

            @if(! $woo)
                <div class="mt-5">
                    <x-ui.empty-state
                        :title="__('Never checked')"
                        :description="__('The store check runs daily on sites with WooCommerce active.')" />
                </div>
            @elseif(! $woo->applicable)
                <div class="mt-5">
                    <x-ui.empty-state
                        :title="__('WooCommerce is not active on this site')"
                        :description="__('The store check does not apply here.')" />
                </div>
            @else
                <dl class="mt-5 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <x-ui.info-row :label="__('Checkout')">
                        @if($woo->checkout_status === null)
                            <span class="text-gray-400">{{ __('Not probed') }}</span>
                        @else
                            <x-ui.badge :variant="$woo->checkout_status === 200 ? 'green' : 'red'">
                                HTTP {{ $woo->checkout_status }}
                            </x-ui.badge>
                        @endif
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Payment gateways')">
                        <x-ui.badge :variant="$woo->gateways_ok ? 'green' : 'red'">
                            {{ $woo->gateways_ok ? __('Responding') : __('None enabled') }}
                        </x-ui.badge>
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Orders stuck pending')">
                        <span @class(['font-medium', 'text-amber-600' => $woo->pending_over_threshold])>
                            {{ $woo->pending_orders_count }}
                        </span>
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Scheduled-sale cron')">
                        <x-ui.badge :variant="$woo->discount_cron_scheduled ? 'green' : 'gray'">
                            {{ $woo->discount_cron_scheduled ? __('Registered') : __('Missing') }}
                        </x-ui.badge>
                    </x-ui.info-row>
                    <x-ui.info-row :label="__('Last checked')">
                        {{ $woo->checked_at?->diffForHumans() ?? '—' }}
                    </x-ui.info-row>
                    @if($woo->last_error)
                        <x-ui.info-row :label="__('Last error')">
                            <span class="text-red-600">{{ $woo->last_error }}</span>
                        </x-ui.info-row>
                    @endif
                </dl>

                @if(! empty($woo->gateways))
                    <div class="mt-5 border-t pt-4">
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Enabled gateways') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($woo->gateways as $gateway)
                                <x-ui.badge variant="gray">{{ is_array($gateway) ? ($gateway['title'] ?? $gateway['id'] ?? '—') : $gateway }}</x-ui.badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </x-ui.card>

    {{-- ─────────────────────── Broken links ──────────────────────── --}}
    @elseif($tab === 'links')
        @php $run = $this->linkRun; @endphp

        <x-ui.card>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">{{ __('Broken links') }}</p>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Internal links found in the site\'s own content, checked monthly. Reported as the change since last month — a list of forty that never moves is never read.') }}
                    </p>
                </div>
                <x-ui.button variant="secondary" class="shrink-0" wire:click="runLinkCheck">
                    {{ __('Scan now') }}
                </x-ui.button>
            </div>

            @if(! $run)
                <div class="mt-5">
                    <x-ui.empty-state
                        :title="__('Never scanned')"
                        :description="__('The scan runs monthly, before the report is generated.')" />
                </div>
            @else
                {{-- The headline is the difference (SPEC §5.7), not the state. --}}
                <p class="mt-5 text-sm text-gray-700">
                    @if($run->new_broken_count === 0 && $run->resolved_count === 0)
                        {{ __('No change since the previous scan.') }}
                    @else
                        {{ trans_choice('{1} :count newly broken link|[2,*] :count newly broken links', $run->new_broken_count, ['count' => $run->new_broken_count]) }},
                        {{ trans_choice('{1} :count resolved|[2,*] :count resolved', $run->resolved_count, ['count' => $run->resolved_count]) }}
                        {{ __('since the previous scan.') }}
                    @endif
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-4">
                    <x-ui.stat-box :label="__('URLs checked')" :value="$run->total_urls" />
                    <x-ui.stat-box :label="__('Broken')" :value="$run->broken_count" />
                    <x-ui.stat-box :label="__('Redirect chains')" :value="$run->chains_count" />
                    <x-ui.stat-box :label="__('Scanned')" :value="$run->finished_at?->diffForHumans() ?? __('Running')" />
                </div>

                @if($this->brokenLinks->isNotEmpty())
                    <div class="mt-5 overflow-x-auto border-t pt-4">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th class="pb-2 pr-4 font-medium">{{ __('URL') }}</th>
                                    <th class="pb-2 pr-4 font-medium">{{ __('Found on') }}</th>
                                    <th class="pb-2 font-medium">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach($this->brokenLinks as $link)
                                    <tr>
                                        <td class="max-w-md truncate py-2 pr-4">
                                            <a href="{{ $link->url }}" target="_blank" rel="noopener"
                                               class="text-accent-600 hover:underline">{{ $link->url }}</a>
                                        </td>
                                        <td class="max-w-xs truncate py-2 pr-4 text-gray-500">
                                            @if($link->source_page)
                                                <a href="{{ $link->source_page }}" target="_blank" rel="noopener"
                                                   class="hover:underline">{{ $link->source_page }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2">
                                            <x-ui.badge variant="red">{{ $link->status_code ?: __('No response') }}</x-ui.badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if($this->redirectChains->isNotEmpty())
                    <div class="mt-5 border-t pt-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Redirect chains') }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ __('These resolve, but through more hops than necessary. Shown for a decision, not acted on automatically.') }}
                        </p>
                        <div class="mt-3 space-y-1.5">
                            @foreach($this->redirectChains as $chain)
                                <p class="truncate text-xs text-gray-600">
                                    @foreach($chain->redirect_chain ?? [] as $i => $hop)
                                        @if($i > 0)<span class="text-gray-400">&rarr;</span>@endif
                                        <span>{{ $hop['url'] ?? '' }}</span><span class="text-gray-400">({{ $hop['status_code'] ?? '?' }})</span>
                                    @endforeach
                                </p>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </x-ui.card>

    {{-- ───────────────────────── PHP errors ──────────────────────── --}}
    @elseif($tab === 'errors')
        @if($this->errorsBySource->isNotEmpty())
            <x-ui.card class="mb-4">
                <p class="text-sm font-medium text-gray-900">{{ __('Where the errors come from') }}</p>
                <p class="mt-1 text-sm text-gray-500">
                    {{ __('Attributed from the file path to the plugin or theme that produced them.') }}
                </p>
                <div class="mt-4 space-y-2">
                    @foreach($this->errorsBySource as $source)
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <span class="min-w-0 flex-1 truncate">
                                <x-ui.badge variant="gray">{{ $source->source_type }}</x-ui.badge>
                                <span class="ml-1.5 text-gray-900">{{ $source->source_slug ?: __('unattributed') }}</span>
                            </span>
                            <span class="shrink-0 text-gray-500">
                                {{ trans_choice('{1} :count occurrence|[2,*] :count occurrences', (int) $source->occurrences, ['count' => (int) $source->occurrences]) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        <x-ui.card>
            <p class="text-sm font-medium text-gray-900">{{ __('Unresolved errors') }}</p>

            @if($this->phpErrors->isEmpty())
                <div class="mt-4">
                    <x-ui.empty-state
                        :title="__('No unresolved PHP errors')"
                        :description="__('The connector reports errors as they happen, deduplicated by signature.')" />
                </div>
            @else
                <div class="mt-4 divide-y">
                    @foreach($this->phpErrors as $error)
                        <div class="py-3">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <x-ui.badge :variant="$error->level === 'fatal' ? 'red' : 'yellow'">
                                            {{ $error->level }}
                                        </x-ui.badge>
                                        @if($error->source_slug)
                                            <span class="text-xs text-gray-500">{{ $error->source_slug }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 break-words text-sm text-gray-900">{{ $error->message }}</p>
                                    @if($error->file)
                                        <p class="mt-0.5 truncate text-xs text-gray-400">{{ $error->file }}:{{ $error->line }}</p>
                                    @endif
                                </div>
                                <div class="shrink-0 text-right text-xs text-gray-500">
                                    <p>&times;{{ $error->count }}</p>
                                    <p>{{ $error->last_seen_at?->diffForHumans() }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
