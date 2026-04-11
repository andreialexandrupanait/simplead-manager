<div>
    <x-ui.page-header
        title="{{ $seoContent && $title ? $title : __('New Article') }}"
        subtitle="{{ __('AI-powered SEO content generator') }}"
    >
        <a href="{{ route('seo.content.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
            {{ __('Back to Articles') }}
        </a>
    </x-ui.page-header>

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Left column (2/3) --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Brief --}}
            <x-ui.card>
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('Article Brief') }}</h3>
                    @if(!empty($this->configuredProviders))
                        <x-ui.button variant="primary" size="sm" wire:click="generateArticle" wire:loading.attr="disabled" wire:target="generateArticle">
                            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="generateArticle" />
                            {{ __('Generate with AI') }}
                        </x-ui.button>
                    @endif
                </div>
                <div class="space-y-4">
                    {{-- Topic (main input) --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Topic / Target Keyword') }} *</label>
                        <input type="text" wire:model="targetKeyword" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __('e.g., marketing digital pentru afaceri mici') }}" />
                        @error('targetKeyword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Secondary Keywords') }}</label>
                            <input type="text" wire:model="secondaryKeywords" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __('keyword1, keyword2, ...') }}" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Target Audience') }}</label>
                            <input type="text" wire:model="targetAudience" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __('e.g., antreprenori') }}" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Title') }} <span class="text-xs text-gray-400">({{ __('auto-generated if empty') }})</span></label>
                            <input type="text" wire:model="title" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __('Leave empty for AI title') }}" />
                        </div>
                    </div>

                    {{-- AI Provider & Model --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('AI Provider') }}</label>
                            @if(empty($this->configuredProviders))
                                <p class="text-xs text-red-600 mt-1">{{ __('No AI providers configured.') }} <a href="{{ route('settings.integrations') }}" class="underline">{{ __('Configure in Settings') }}</a></p>
                            @else
                                <select wire:model.live="aiProvider" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                    @foreach($this->configuredProviders as $key => $provider)
                                        <option value="{{ $key }}">{{ $provider['label'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Model') }}</label>
                            @if($aiProvider && isset($this->configuredProviders[$aiProvider]))
                                <select wire:model="aiModel" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                    @foreach($this->configuredProviders[$aiProvider]['models'] as $modelKey => $modelLabel)
                                        <option value="{{ $modelKey }}">{{ $modelLabel }}</option>
                                    @endforeach
                                </select>
                            @else
                                <select disabled class="w-full rounded-lg border-gray-300 bg-gray-50 text-sm text-gray-400">
                                    <option>{{ __('Select a provider first') }}</option>
                                </select>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Tone') }}</label>
                            <select wire:model="tone" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                <option value="professional">{{ __('Professional') }}</option>
                                <option value="casual">{{ __('Casual') }}</option>
                                <option value="authoritative">{{ __('Authoritative') }}</option>
                                <option value="friendly">{{ __('Friendly') }}</option>
                                <option value="educational">{{ __('Educational') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Persona') }}</label>
                            <select wire:model="persona" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                <option value="noi">{{ __('We (noi)') }}</option>
                                <option value="eu">{{ __('I (eu)') }}</option>
                                <option value="neutru">{{ __('Neutral') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Word Count') }}</label>
                            <select wire:model="targetWordCount" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                <option value="500">500</option>
                                <option value="800">800</option>
                                <option value="1000">1000</option>
                                <option value="1500">1500</option>
                                <option value="2000">2000</option>
                                <option value="3000">3000</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Site') }}</label>
                            <select wire:model.live="siteId" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                                <option value="">{{ __('No site') }}</option>
                                @foreach($this->sites as $site)
                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Context & Instructions --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Context & Instructions') }}</label>
                        <textarea wire:model="brief" rows="3" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __('Paste context from your Claude conversations, specific details, instructions...') }}"></textarea>
                        <p class="mt-1 text-xs text-gray-400">{{ __('Add any relevant context: brand info, key points, competitor links, style preferences, notes from conversations.') }}</p>
                    </div>
                </div>
            </x-ui.card>

            {{-- Site AI Context (collapsible, shown when site selected) --}}
            @if($siteId)
                <x-ui.card x-data="{ open: false }">
                    <button @click="open = !open" type="button" class="flex w-full items-center justify-between text-sm">
                        <h3 class="font-semibold text-gray-900">{{ __('Site Brand Context') }}</h3>
                        <div class="flex items-center gap-2">
                            @if($siteAiContext)
                                <span class="text-xs text-green-600">{{ __('Configured') }}</span>
                            @else
                                <span class="text-xs text-gray-400">{{ __('Not set') }}</span>
                            @endif
                            <svg class="h-4 w-4 text-gray-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </button>
                    <div x-show="open" x-collapse x-cloak class="mt-3 space-y-3">
                        <p class="text-xs text-gray-500">{{ __('Persistent context about this site/brand. Automatically included in every article generation for this site.') }}</p>
                        <textarea wire:model="siteAiContext" rows="4" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __("e.g., SimpleAd este o agentie de marketing digital din Hunedoara. Oferim servicii de web design, SEO, social media. Tonul comunicarii: profesional dar prietenos. Publicul: IMM-uri si antreprenori romani.") }}"></textarea>
                        <div class="flex items-center justify-between">
                            <button type="button" wire:click="autoDetectSiteContext" wire:loading.attr="disabled" wire:target="autoDetectSiteContext"
                                    class="text-sm text-purple-600 hover:text-purple-800 disabled:opacity-50">
                                <span wire:loading.remove wire:target="autoDetectSiteContext">{{ __('Auto-detect from site') }}</span>
                                <span wire:loading wire:target="autoDetectSiteContext">{{ __('Analyzing site...') }}</span>
                            </button>
                            <x-ui.button variant="secondary" size="sm" wire:click="saveSiteContext">{{ __('Save Site Context') }}</x-ui.button>
                        </div>
                    </div>
                </x-ui.card>
            @endif

            {{-- Job progress --}}
            @if($this->hasRunningJobs)
                <div wire:poll.3s="checkJobProgress">
                    <x-ui.job-progress job-key="generate" :jobs="$trackedJobs" title="{{ __('Generating article...') }}" />
                </div>
            @endif

            {{-- Generated content (preview or edit mode) --}}
            @if($content)
                <x-ui.card>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">
                            @if($editing)
                                {{ __('Editing Article') }}
                            @else
                                {{ __('Generated Article') }}
                            @endif
                        </h3>
                        <div class="flex items-center gap-2">
                            @if(!$editing)
                                <x-ui.button variant="secondary" size="sm" wire:click="toggleEditing">
                                    {{ __('Edit HTML') }}
                                </x-ui.button>
                                <x-ui.button variant="primary" size="sm" wire:click="generateArticle" wire:loading.attr="disabled" wire:target="generateArticle">
                                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="generateArticle" />
                                    {{ __('Regenerate') }}
                                </x-ui.button>
                            @else
                                <x-ui.button variant="secondary" size="sm" wire:click="saveDraft" wire:loading.attr="disabled">
                                    {{ __('Save Changes') }}
                                </x-ui.button>
                                <x-ui.button variant="secondary" size="sm" wire:click="toggleEditing">
                                    {{ __('Done Editing') }}
                                </x-ui.button>
                            @endif
                        </div>
                    </div>

                    {{-- Meta Description --}}
                    <div class="mb-4 rounded-lg bg-gray-50 p-3">
                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('Meta Description') }}</label>
                        @if($editing)
                            <textarea wire:model.blur="metaDescription" rows="2" maxlength="160" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
                            <p class="mt-1 text-xs text-gray-400">{{ mb_strlen($metaDescription) }}/160</p>
                        @else
                            <p class="text-sm text-gray-700">{{ $metaDescription ?: '—' }}</p>
                        @endif
                    </div>

                    {{-- Article body --}}
                    @if($editing)
                        <x-ui.rich-editor wire-model="content" />
                    @else
                        <div class="prose prose-sm max-w-none prose-headings:text-gray-900 prose-h2:text-lg prose-h2:mt-6 prose-h2:mb-3 prose-h3:text-base prose-p:text-gray-700 prose-strong:text-gray-900 prose-ul:text-gray-700 prose-ol:text-gray-700 prose-blockquote:border-purple-300 prose-blockquote:text-gray-600 prose-a:text-purple-600">
                            {!! $content !!}
                        </div>
                    @endif
                </x-ui.card>

                {{-- Corrections / Refinements --}}
                @if(!$editing && $seoContent)
                    <x-ui.card>
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">{{ __('Corrections & Refinements') }}</h3>
                        <p class="mb-3 text-xs text-gray-500">{{ __('Describe what you want changed. The AI will apply your corrections while preserving the rest of the article.') }}</p>
                        <textarea wire:model="corrections" rows="3" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500" placeholder="{{ __("e.g., Change the tone to be more formal. Add a section about pricing. Remove the second paragraph. Make the conclusion shorter.") }}"></textarea>
                        @if($corrections)
                            <div class="mt-3 flex justify-end">
                                <x-ui.button variant="primary" size="sm" wire:click="applyCorrections" wire:loading.attr="disabled" wire:target="applyCorrections">
                                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="applyCorrections" />
                                    {{ __('Apply Corrections') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </x-ui.card>
                @endif

                {{-- Publish --}}
                @if($seoContent && $siteId && !$editing)
                    <x-ui.card>
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">{{ __('Publish to WordPress') }}</h3>
                                <p class="text-xs text-gray-500">{{ __('Creates a draft post on the selected WordPress site.') }}</p>
                            </div>
                            <x-ui.button variant="primary" wire:click="publishToWordPress" wire:loading.attr="disabled">
                                {{ __('Publish') }}
                            </x-ui.button>
                        </div>
                    </x-ui.card>
                @endif
            @endif
        </div>

        {{-- Right column (1/3) --}}
        <div class="space-y-6">
            {{-- SEO Score Panel --}}
            @if($seoContent && !empty($this->seoScore))
                <x-ui.card>
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('SEO Score') }}</h3>
                    <div class="mb-4 flex items-center justify-center">
                        <div @class([
                            'flex h-20 w-20 items-center justify-center rounded-full text-2xl font-bold',
                            'bg-green-100 text-green-700' => ($this->seoScore['score'] ?? 0) >= 80,
                            'bg-yellow-100 text-yellow-700' => ($this->seoScore['score'] ?? 0) >= 50 && ($this->seoScore['score'] ?? 0) < 80,
                            'bg-red-100 text-red-700' => ($this->seoScore['score'] ?? 0) < 50,
                        ])>
                            {{ $this->seoScore['score'] ?? 0 }}
                        </div>
                    </div>

                    <div class="space-y-2">
                        @foreach($this->seoScore['checks'] ?? [] as $check)
                            <div class="flex items-start gap-2 text-sm">
                                @if($check['status'] === 'pass')
                                    <span class="mt-0.5 text-green-500">&#10003;</span>
                                @elseif($check['status'] === 'warn')
                                    <span class="mt-0.5 text-yellow-500">&#9888;</span>
                                @else
                                    <span class="mt-0.5 text-red-500">&#10007;</span>
                                @endif
                                <span class="text-gray-600">{{ $check['message'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 border-t border-gray-200 pt-3 text-xs text-gray-500">
                        <div>{{ __('Words') }}: {{ $this->seoScore['word_count'] ?? 0 }}</div>
                        <div>{{ __('Keyword Density') }}: {{ $this->seoScore['keyword_density'] ?? 0 }}%</div>
                    </div>
                </x-ui.card>
            @endif

            {{-- Revisions --}}
            @if($seoContent && $this->revisions->isNotEmpty())
                <x-ui.card>
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Revisions') }}</h3>
                    <div class="space-y-2">
                        @foreach($this->revisions as $rev)
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                <div>
                                    <span class="font-medium text-gray-700">{{ ucfirst($rev->source) }}</span>
                                    <span class="text-xs text-gray-400">{{ $rev->created_at->diffForHumans() }}</span>
                                </div>
                                <button wire:click="restoreRevision({{ $rev->id }})" class="text-xs text-purple-600 hover:text-purple-800">{{ __('Restore') }}</button>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            {{-- Info --}}
            @if($seoContent)
                <x-ui.card>
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Info') }}</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('Status') }}</dt>
                            <dd><span class="rounded-full bg-{{ $seoContent->status_color }}-100 px-2 py-0.5 text-xs font-semibold text-{{ $seoContent->status_color }}-800">{{ $seoContent->status_label }}</span></dd>
                        </div>
                        @if($seoContent->ai_provider)
                            <div class="flex justify-between">
                                <dt class="text-gray-500">{{ __('AI') }}</dt>
                                <dd class="text-gray-600 text-xs">{{ $seoContent->ai_model ?? $seoContent->ai_provider }}</dd>
                            </div>
                        @endif
                        @if($seoContent->wp_post_id)
                            <div class="flex justify-between">
                                <dt class="text-gray-500">{{ __('WP Post ID') }}</dt>
                                <dd class="text-gray-900">{{ $seoContent->wp_post_id }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('Created') }}</dt>
                            <dd class="text-gray-600">{{ $seoContent->created_at->format('M d, Y H:i') }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
