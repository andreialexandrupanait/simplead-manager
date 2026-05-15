<x-guest-layout>
    <h2 class="text-xl font-semibold text-gray-900 mb-6">Reset password</h2>

    <form method="POST" action="{{ route('password.store') }}" class="space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <x-ui.input type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
            <x-ui.input type="password" name="password" required autocomplete="new-password" />
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
            <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            @error('password_confirmation')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.button type="submit" class="w-full">Reset Password</x-ui.button>
    </form>
</x-guest-layout>
