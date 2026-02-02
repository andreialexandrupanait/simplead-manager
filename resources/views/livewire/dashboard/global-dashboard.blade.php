<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
        <p class="mt-1 text-sm text-gray-500">Overview of all managed sites</p>
    </div>

    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Total Sites</div>
            <div class="mt-1 text-3xl font-bold text-gray-900">{{ \App\Models\Site::count() }}</div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Sites Up</div>
            <div class="mt-1 text-3xl font-bold text-green-600">{{ \App\Models\Site::where('is_up', true)->count() }}</div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Sites Down</div>
            <div class="mt-1 text-3xl font-bold text-red-600">{{ \App\Models\Site::where('is_up', false)->count() }}</div>
        </x-ui.card>

        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Total Clients</div>
            <div class="mt-1 text-3xl font-bold text-gray-900">{{ \App\Models\Client::count() }}</div>
        </x-ui.card>
    </div>
</div>
