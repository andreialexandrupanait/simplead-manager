<div class="mx-auto max-w-2xl space-y-6">
    <div>
        <a href="{{ route('audits.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">
            <x-icons.arrow-left class="h-4 w-4" aria-hidden="true" />
            {{ __('Înapoi la audituri') }}
        </a>
        <h1 class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">{{ __('Audit nou') }}</h1>
    </div>

    <form wire:submit="save">
        <x-ui.card class="space-y-6">
            {{-- Target type --}}
            <div>
                <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Țintă') }}</span>
                <div class="mt-2 flex gap-4">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="radio" wire:model.live="targetType" value="prospect" class="text-accent-600 focus:ring-accent-500">
                        {{ __('Prospect') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="radio" wire:model.live="targetType" value="site" class="text-accent-600 focus:ring-accent-500">
                        {{ __('Site conectat') }}
                    </label>
                </div>
            </div>

            @if ($targetType === 'site')
                <div>
                    <label for="siteId" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Site') }}</label>
                    <x-ui.select id="siteId" wire:model="siteId" class="mt-1">
                        <option value="">{{ __('— alege un site —') }}</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }} ({{ $site->url }})</option>
                        @endforeach
                    </x-ui.select>
                    @error('siteId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            @else
                <div>
                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Prospect') }}</span>
                    <div class="mt-2 flex gap-4">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" wire:model.live="prospectMode" value="new" class="text-accent-600 focus:ring-accent-500">
                            {{ __('Nou') }}
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" wire:model.live="prospectMode" value="existing" class="text-accent-600 focus:ring-accent-500">
                            {{ __('Existent') }}
                        </label>
                    </div>
                </div>

                @if ($prospectMode === 'existing')
                    <div>
                        <label for="prospectId" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Alege prospectul') }}</label>
                        <x-ui.select id="prospectId" wire:model="prospectId" class="mt-1">
                            <option value="">{{ __('— alege un prospect —') }}</option>
                            @foreach ($prospects as $prospect)
                                <option value="{{ $prospect->id }}">{{ $prospect->name }} ({{ $prospect->url }})</option>
                            @endforeach
                        </x-ui.select>
                        @error('prospectId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Nume prospect') }}</label>
                            <x-ui.input id="name" wire:model="name" class="mt-1" :error="$errors->first('name')" />
                            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="prospectUrl" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('URL') }}</label>
                            <x-ui.input id="prospectUrl" type="url" wire:model="prospectUrl" placeholder="https://exemplu.ro" class="mt-1" :error="$errors->first('prospectUrl')" />
                            @error('prospectUrl') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="profile" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Profil') }}</label>
                            <x-ui.select id="profile" wire:model="profile" class="mt-1">
                                @foreach ($profiles as $p)
                                    <option value="{{ $p->value }}">{{ $p->value }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                        <div>
                            <label for="contactName" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Contact (opțional)') }}</label>
                            <x-ui.input id="contactName" wire:model="contactName" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <label for="contactEmail" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Email contact (opțional)') }}</label>
                            <x-ui.input id="contactEmail" type="email" wire:model="contactEmail" class="mt-1" :error="$errors->first('contactEmail')" />
                            @error('contactEmail') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif
            @endif

            <div>
                <label for="contextNotes" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Note de context (opțional)') }}</label>
                <textarea id="contextNotes" wire:model="contextNotes" rows="3"
                    class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-accent-500 focus:ring-1 focus:ring-accent-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"></textarea>
            </div>

            <div class="flex justify-end gap-3">
                <x-ui.button :href="route('audits.index')" variant="secondary" wire:navigate>{{ __('Anulează') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary">
                    <span wire:loading.remove wire:target="save">{{ __('Creează audit') }}</span>
                    <span wire:loading wire:target="save">{{ __('Se creează…') }}</span>
                </x-ui.button>
            </div>
        </x-ui.card>
    </form>
</div>
