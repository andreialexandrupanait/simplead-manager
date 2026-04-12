<x-guest-layout>
    <h2 class="text-xl font-bold text-gray-900 mb-6">Create your account</h2>

    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <x-ui.input type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            @error('name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <x-ui.input type="email" name="email" :value="old('email')" required autocomplete="username" />
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
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

        <x-ui.button type="submit" class="w-full">Sign Up</x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500">
        Already have an account?
        <a href="{{ route('login') }}" class="text-accent-600 hover:text-accent-700 font-medium">Log in</a>
    </p>
</x-guest-layout>
