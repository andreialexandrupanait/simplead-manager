<x-guest-layout>
    <h2 class="text-xl font-bold text-gray-900 mb-6">Welcome back</h2>

    @if(session('status'))
        <div class="mb-4 text-sm font-medium text-green-600">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <x-ui.input type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <x-ui.input type="password" name="password" required autocomplete="current-password" />
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                Remember me
            </label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-sm text-purple-600 hover:text-purple-700">
                    Forgot password?
                </a>
            @endif
        </div>

        <x-ui.button type="submit" class="w-full">Log In</x-ui.button>
    </form>

    @if (Route::has('register'))
        <p class="mt-6 text-center text-sm text-gray-500">
            Don't have an account?
            <a href="{{ route('register') }}" class="text-purple-600 hover:text-purple-700 font-medium">Sign up</a>
        </p>
    @endif
</x-guest-layout>
