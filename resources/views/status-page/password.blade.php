<x-layouts.status-page :title="$statusPage->title . ' — Status'" :primaryColor="$statusPage->primary_color">
    <div class="flex min-h-screen items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <div class="rounded-xl bg-white p-8 shadow-sm ring-1 ring-gray-950/5">
                <div class="text-center mb-6">
                    @if($statusPage->logo_url)
                        <img src="{{ $statusPage->logo_url }}" alt="{{ $statusPage->title }}" class="mx-auto mb-4 h-10">
                    @endif
                    <h1 class="text-lg font-semibold text-gray-900">{{ $statusPage->title }}</h1>
                    <p class="mt-1 text-sm text-gray-500">This status page is password-protected.</p>
                </div>

                <form method="POST" action="{{ route('status-page.auth', $statusPage->slug) }}">
                    @csrf
                    <div class="mb-4">
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                        <input type="password" name="password" id="password" required autofocus
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500"
                            placeholder="Enter password">
                        @error('password')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit"
                        class="w-full rounded-lg px-4 py-2 text-sm font-medium text-white shadow-sm"
                        style="background-color: var(--primary-color);">
                        View Status Page
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.status-page>
