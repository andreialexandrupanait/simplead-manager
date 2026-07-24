@php use App\Enums\CheckState; @endphp
<div class="space-y-5">
    {{-- header --}}
    <div>
        <a href="{{ route('audits.show', $audit) }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">
            <x-icons.arrow-left class="h-4 w-4" aria-hidden="true" />
            {{ __('Înapoi la audit') }}
        </a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">
                {{ __('Validare') }} — {{ $audit->site?->name ?? $audit->prospect?->name ?? $audit->url }}
            </h1>
            <x-ui.badge :variant="$audit->status->badge()">{{ $audit->status->label() }}</x-ui.badge>
            <span class="text-xs text-gray-400">{{ __('metodologia v2 — fără scoruri') }}</span>
        </div>
    </div>

    @if ($this->readOnly())
        <x-ui.alert type="warning">{{ __('Audit validat — stările și recomandările sunt doar în citire.') }}</x-ui.alert>
    @endif

    <div class="flex flex-col gap-5 xl:flex-row xl:items-start">
        {{-- left: sections --}}
        <div class="w-full flex-none space-y-1 xl:w-60">
            <div class="px-2 pb-2 text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Secțiuni — setate/total') }}</div>
            @foreach ($this->sections() as $section)
                @php
                    $counts = $this->sectionCounts()[$section['key']] ?? ['set' => 0, 'remaining' => 0, 'total' => 0];
                    $isActive = $section['key'] === $activeSection;
                @endphp
                <button type="button" wire:click="selectSection('{{ $section['key'] }}')"
                    @class([
                        'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition-colors',
                        'bg-accent-500/10 font-semibold text-accent-700 dark:text-accent-300' => $isActive,
                        'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700/40' => ! $isActive,
                    ])>
                    <span class="font-mono text-xs">{{ $section['nr'] }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ $section['name'] }}</span>
                    <span @class([
                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                        'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300' => $counts['remaining'] === 0,
                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $counts['remaining'] !== 0,
                    ])>{{ $counts['set'] }}/{{ $counts['total'] }}</span>
                </button>
            @endforeach
        </div>

        {{-- center: states + cards --}}
        <div class="min-w-0 flex-1 space-y-4">
            {{-- state panel --}}
            @foreach ($this->subsectionGroups() as $group)
                <x-ui.card :padding="false" class="overflow-hidden">
                    @if ($group['id'])
                        <div class="flex items-center gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2 dark:border-gray-700 dark:bg-gray-900/40">
                            <span class="font-mono text-xs font-semibold text-accent-600 dark:text-accent-400">{{ $group['id'] }}</span>
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $group['name'] }}</span>
                        </div>
                    @endif
                    <div class="divide-y divide-gray-100 dark:divide-gray-700/60">
                        @foreach ($group['checks'] as $check)
                            <div wire:key="check-{{ $check['key'] }}" class="px-4 py-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-xs text-gray-400">{{ $check['key'] }}</span>
                                            @if ($check['sourceLabel'])
                                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ $check['sourceLabel'] }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-0.5 text-sm text-gray-800 dark:text-gray-100">{{ $check['question'] }}</p>

                                        @if ($check['summary']['hasEvidence'])
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                @if ($check['summary']['total'] !== null)
                                                    <span class="font-medium">{{ $check['summary']['total'] }} {{ __('afectate') }}</span>
                                                @endif
                                                @if ($check['summary']['note'])
                                                    · {{ \Illuminate\Support\Str::limit($check['summary']['note'], 160) }}
                                                @endif
                                                @if (! empty($check['summary']['urls']))
                                                    <ul class="mt-1 space-y-0.5">
                                                        @foreach ($check['summary']['urls'] as $u)
                                                            <li class="truncate font-mono text-[11px] text-gray-400">{{ $u }}</li>
                                                        @endforeach
                                                        @if ($check['summary']['more'] > 0)
                                                            <li class="text-[11px] text-gray-400">+{{ $check['summary']['more'] }} {{ __('mai multe') }}</li>
                                                        @endif
                                                    </ul>
                                                @endif
                                            </div>
                                        @endif

                                        @if ($check['state'] === CheckState::NuSeAplica && ! empty($check['evidence']['reason']))
                                            <p class="mt-1 text-xs italic text-gray-500 dark:text-gray-400">{{ __('Motiv') }}: {{ $check['evidence']['reason'] }}</p>
                                        @endif
                                    </div>

                                    {{-- state controls --}}
                                    @unless ($this->readOnly())
                                        <div class="flex flex-none flex-wrap items-center gap-1">
                                            @foreach ([CheckState::Exista, CheckState::NuExista, CheckState::NuSeAplica] as $opt)
                                                <button type="button" wire:click="setState('{{ $check['key'] }}', '{{ $opt->value }}')"
                                                    @class([
                                                        'rounded-md border px-2 py-1 text-xs font-medium transition-colors',
                                                        'border-green-500 bg-green-500 text-white' => $check['state'] === $opt && $opt === CheckState::Exista,
                                                        'border-red-500 bg-red-500 text-white' => $check['state'] === $opt && $opt === CheckState::NuExista,
                                                        'border-gray-500 bg-gray-500 text-white' => $check['state'] === $opt && $opt === CheckState::NuSeAplica,
                                                        'border-gray-300 text-gray-600 hover:border-gray-400 dark:border-gray-600 dark:text-gray-300' => $check['state'] !== $opt,
                                                    ])>{{ $opt->label() }}</button>
                                            @endforeach
                                        </div>
                                    @else
                                        @if ($check['state'] instanceof CheckState)
                                            <x-ui.badge variant="gray">{{ $check['state']->label() }}</x-ui.badge>
                                        @endif
                                    @endunless
                                </div>

                                {{-- NU SE APLICĂ reason prompt --}}
                                @if ($reasonForKey === $check['key'])
                                    <div class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-2 dark:bg-gray-900/40">
                                        <input type="text" wire:model="reasonText" placeholder="{{ __('Motivul „nu se aplică"…') }}"
                                            class="flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                        <x-ui.button wire:click="confirmNuSeAplica" variant="primary" size="sm">{{ __('Salvează') }}</x-ui.button>
                                        <x-ui.button wire:click="cancelReason" variant="ghost" size="sm">{{ __('Anulează') }}</x-ui.button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach

            {{-- cards --}}
            <div class="flex items-center justify-between pt-2">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Recomandări') }} ({{ count($this->cards()) }})</h2>
                @unless ($this->readOnly())
                    <x-ui.button wire:click="newCard" variant="secondary" size="sm">{{ __('+ Recomandare nouă') }}</x-ui.button>
                @endunless
            </div>

            {{-- card form --}}
            @if ($showCardForm && ! $this->readOnly())
                <x-ui.card class="space-y-4 border-accent-300 dark:border-accent-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $editingCardId ? __('Editează recomandarea') : __('Recomandare nouă') }}
                    </h3>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Titlu') }}</label>
                        <x-ui.input wire:model="cardTitle" class="mt-1" :error="$errors->first('cardTitle')" />
                        @error('cardTitle') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Diagnostic (max 3 fraze)') }}</label>
                        <textarea wire:model="cardDiagnostic" rows="3" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"></textarea>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Impact') }}</label>
                            <x-ui.select wire:model="cardImpact" class="mt-1">
                                <option value="mare">{{ __('mare') }}</option>
                                <option value="mediu">{{ __('mediu') }}</option>
                                <option value="mic">{{ __('mic') }}</option>
                            </x-ui.select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Efort') }}</label>
                            <x-ui.select wire:model="cardEffort" class="mt-1">
                                <option value="mare">{{ __('mare') }}</option>
                                <option value="mediu">{{ __('mediu') }}</option>
                                <option value="mic">{{ __('mic') }}</option>
                            </x-ui.select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Echipă') }}</label>
                            <x-ui.select wire:model="cardTeam" class="mt-1">
                                <option value="">—</option>
                                @foreach ($teams as $team)
                                    <option value="{{ $team->value }}">{{ $team->value }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Goluri acoperite (NU EXISTĂ)') }}</span>
                        @if (empty($this->gapOptions()))
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Nicio verificare NU EXISTĂ în această secțiune — marchează întâi golurile.') }}</p>
                        @else
                            <div class="mt-2 space-y-1">
                                @foreach ($this->gapOptions() as $gap)
                                    <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                                        <input type="checkbox" wire:model="cardGaps" value="{{ $gap['key'] }}" class="mt-0.5 rounded text-accent-600 focus:ring-accent-500">
                                        <span><span class="font-mono text-xs text-gray-400">{{ $gap['key'] }}</span> {{ $gap['question'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                        @error('cardGaps') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- per-URL table --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Tabel per-URL (nivel copy-paste)') }}</span>
                            <x-ui.button wire:click="addTableRow" variant="ghost" size="xs" type="button">{{ __('+ rând') }}</x-ui.button>
                        </div>
                        @if (! empty($cardTableRows))
                            <div class="mt-2 space-y-2">
                                @foreach ($cardTableRows as $i => $row)
                                    <div wire:key="row-{{ $i }}" class="flex flex-wrap items-center gap-2">
                                        <input type="text" wire:model="cardTableRows.{{ $i }}.url" placeholder="URL" class="min-w-40 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                        <input type="text" wire:model="cardTableRows.{{ $i }}.current" placeholder="{{ __('valoare actuală') }}" class="min-w-32 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                        <input type="text" wire:model="cardTableRows.{{ $i }}.recommended" placeholder="{{ __('recomandare') }}" class="min-w-32 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                        <button type="button" wire:click="removeTableRow({{ $i }})" class="text-gray-400 hover:text-red-500"><x-icons.trash class="h-4 w-4" /></button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if ($editingCardId)
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" wire:model="cardEvidenceConfirmed" class="rounded text-accent-600 focus:ring-accent-500">
                            {{ __('Dovadă confirmată (deblochează validarea unui card „de verificat")') }}
                        </label>
                    @endif

                    <div class="flex justify-end gap-3">
                        <x-ui.button wire:click="cancelCard" variant="secondary" type="button">{{ __('Anulează') }}</x-ui.button>
                        <x-ui.button wire:click="saveCard" variant="primary" type="button">{{ __('Salvează recomandarea') }}</x-ui.button>
                    </div>
                </x-ui.card>
            @endif

            @forelse ($this->cards() as $card)
                <x-ui.card wire:key="card-{{ $card->id }}" class="space-y-2">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $card->title }}</h3>
                                @php $vBadge = ['DRAFT_AI' => 'yellow', 'APROBAT' => 'green', 'EDITAT' => 'blue', 'RESPINS' => 'red'][$card->validation] ?? 'gray'; @endphp
                                <x-ui.badge :variant="$vBadge">{{ $card->validation }}</x-ui.badge>
                                @if ($card->needs_verification)
                                    <x-ui.badge variant="orange">{{ __('de verificat') }}</x-ui.badge>
                                @endif
                                @if ($card->auto_approved)
                                    <x-ui.badge variant="gray">{{ __('auto') }}</x-ui.badge>
                                @endif
                            </div>
                            @if ($card->recommendation)
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $card->recommendation }}</p>
                            @endif
                            <p class="mt-1 text-xs text-gray-400">{{ $card->evidence_text }}</p>
                        </div>
                        @unless ($this->readOnly())
                            <div class="flex flex-none items-center gap-1">
                                @if ($card->validation === 'DRAFT_AI')
                                    <x-ui.button wire:click="approveCard({{ $card->id }})" variant="primary" size="xs">{{ __('Aprobă') }}</x-ui.button>
                                    <x-ui.button wire:click="rejectCard({{ $card->id }})" variant="danger" size="xs">{{ __('Respinge') }}</x-ui.button>
                                @endif
                                @if ($card->validation !== 'RESPINS')
                                    <x-ui.button wire:click="editCard({{ $card->id }})" variant="ghost" size="xs">{{ __('Editează') }}</x-ui.button>
                                @endif
                            </div>
                        @endunless
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Nicio recomandare în această secțiune — creează câte un card pentru fiecare verificare NU EXISTĂ.') }}
                    </p>
                </x-ui.card>
            @endforelse
        </div>
    </div>
</div>
