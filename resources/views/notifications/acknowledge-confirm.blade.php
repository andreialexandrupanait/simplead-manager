<x-guest-layout>
    <div class="text-center">
        @if($alreadyAcknowledged)
            <svg class="mx-auto h-12 w-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <h2 class="mt-4 text-xl font-semibold text-gray-900">Already Acknowledged</h2>
            <p class="mt-2 text-sm text-gray-600">
                {{ $event }} {{ $site ? "for {$site}" : '' }} has already been acknowledged.
            </p>
        @else
            <svg class="mx-auto h-12 w-12 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
            <h2 class="mt-4 text-xl font-semibold text-gray-900">Acknowledge Alert</h2>
            <p class="mt-2 text-sm text-gray-600">
                Confirm you want to acknowledge <span class="font-medium">{{ $event }}</span>{{ $site ? " for {$site}" : '' }}.
                This cancels any pending escalation.
            </p>

            <form method="POST" action="{{ route('notifications.ack.confirm', $token) }}" class="mt-6">
                @csrf
                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-lg bg-accent-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-accent-700 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2"
                >
                    Confirm acknowledgement
                </button>
            </form>
        @endif
    </div>
</x-guest-layout>
