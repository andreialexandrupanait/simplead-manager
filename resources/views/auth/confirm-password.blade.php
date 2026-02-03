<x-guest-layout>
    <h2 class="text-xl font-bold text-gray-900 mb-2">Confirm password</h2>
    <p class="text-sm text-gray-500 mb-6">
        This is a secure area of the application. Please confirm your password before continuing.
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <x-ui.input type="password" name="password" required autocomplete="current-password" />
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.button type="submit" class="w-full">Confirm</x-ui.button>
    </form>
</x-guest-layout>
