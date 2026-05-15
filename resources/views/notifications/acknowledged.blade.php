<x-guest-layout>
    <div class="text-center">
        <svg class="mx-auto h-12 w-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        <h2 class="mt-4 text-xl font-semibold text-gray-900">Alert Acknowledged</h2>
        <p class="mt-2 text-sm text-gray-600">
            {{ $event }} {{ $site ? "for {$site}" : '' }} has been acknowledged. Escalation cancelled.
        </p>
    </div>
</x-guest-layout>
