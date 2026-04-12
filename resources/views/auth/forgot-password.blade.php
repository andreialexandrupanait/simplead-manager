<x-guest-layout>
    <h2 class="text-xl font-bold text-gray-900 mb-2">Forgot password?</h2>
    <p class="text-sm text-gray-500 mb-6">No worries, we'll send you reset instructions.</p>

    @if(session('status'))
        <div class="mb-4 text-sm font-medium text-green-600">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <x-ui.input type="email" name="email" :value="old('email')" required autofocus />
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <x-ui.button type="submit" class="w-full">Send Reset Link</x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500">
        <a href="{{ route('login') }}" class="text-accent-600 hover:text-accent-700 font-medium">Back to login</a>
    </p>
</x-guest-layout>
