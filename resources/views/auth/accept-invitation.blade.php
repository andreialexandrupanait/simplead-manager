<x-guest-layout>
    <h2 class="text-xl font-bold text-gray-900 mb-2">Accept Invitation</h2>
    <p class="text-sm text-gray-600 mb-6">
        You've been invited by <strong>{{ $invitation->inviter->name }}</strong> as a <strong>{{ $invitation->getRoleEnum()->label() }}</strong>.
    </p>

    <form method="POST" action="{{ route('invitation.accept', $invitation->token) }}" class="space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <x-ui.input type="email" :value="$invitation->email" disabled class="bg-gray-50" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <x-ui.input type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            @error('name')
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
        </div>

        <x-ui.button type="submit" class="w-full">Create Account</x-ui.button>
    </form>
</x-guest-layout>
