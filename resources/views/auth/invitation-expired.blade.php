<x-guest-layout>
    <div class="text-center">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h2 class="mt-4 text-xl font-semibold text-gray-900">Invitation Expired</h2>
        <p class="mt-2 text-sm text-gray-600">This invitation has expired. Please ask the administrator to send a new one.</p>
        <a href="{{ route('login') }}" class="mt-6 inline-block text-sm font-medium text-accent-600 hover:text-accent-500">Back to login</a>
    </div>
</x-guest-layout>
