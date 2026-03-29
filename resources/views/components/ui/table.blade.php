<div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5">
    <table class="min-w-full divide-y divide-gray-200">
        @if(isset($head))
            <thead class="bg-gray-50">
                <tr>{{ $head }}</tr>
            </thead>
        @endif
        <tbody class="divide-y divide-gray-200">
            {{ $slot }}
        </tbody>
    </table>
</div>
