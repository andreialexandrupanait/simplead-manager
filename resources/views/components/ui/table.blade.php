<div class="overflow-x-auto rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        @if(isset($head))
            <thead class="bg-gray-50 dark:bg-gray-900/50">
                <tr>{{ $head }}</tr>
            </thead>
        @endif
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            {{ $slot }}
        </tbody>
    </table>
</div>
