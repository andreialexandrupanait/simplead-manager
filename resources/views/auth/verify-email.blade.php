<x-guest-layout>
    <h2 class="text-xl font-bold text-gray-900 mb-2">Verify your email</h2>
    <p class="text-sm text-gray-500 mb-6">
        Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you?
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 text-sm font-medium text-green-600">
            A new verification link has been sent to the email address you provided during registration.
        </div>
    @endif

    <div class="flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button type="submit">Resend Verification Email</x-ui.button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button type="submit" variant="ghost">Log Out</x-ui.button>
        </form>
    </div>
</x-guest-layout>
