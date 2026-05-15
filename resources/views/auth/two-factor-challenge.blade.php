<x-guest-layout>
    <h2 class="text-xl font-semibold text-gray-900 mb-2">Two-Factor Authentication</h2>
    <p class="text-sm text-gray-500 mb-6">Enter the 6-digit code from your authenticator app, or use a recovery code.</p>

    <form method="POST" action="{{ route('two-factor.store') }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
            <x-ui.input type="text" name="code" required autofocus autocomplete="one-time-code" placeholder="000000 or recovery code" />
            @error('code')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.button type="submit" class="w-full">Verify</x-ui.button>
    </form>
</x-guest-layout>
