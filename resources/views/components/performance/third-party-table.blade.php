@props(['scripts'])

<x-ui.card class="mb-6 overflow-hidden">
    <h3 class="mb-4 text-lg font-semibold text-gray-900">Third-Party Scripts</h3>
    <div class="-mx-6 overflow-x-auto px-6">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">Entity</th>
                    <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500">Size</th>
                    <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500">Blocking Time</th>
                    <th class="px-3 py-2 text-right text-xs font-medium uppercase text-gray-500">Main Thread</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @php
                    $totalSize = 0;
                    $totalBlocking = 0;
                    $totalThread = 0;
                @endphp
                @foreach($scripts as $script)
                    @php
                        $totalSize += $script['transfer_size'] ?? 0;
                        $totalBlocking += $script['blocking_time'] ?? 0;
                        $totalThread += $script['main_thread_time'] ?? 0;
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 text-sm text-gray-900">{{ $script['entity'] }}</td>
                        <td class="px-3 py-2 text-right text-sm text-gray-700">
                            @if(($script['transfer_size'] ?? 0) >= 1048576)
                                {{ round(($script['transfer_size'] ?? 0) / 1048576, 1) }} MB
                            @else
                                {{ round(($script['transfer_size'] ?? 0) / 1024, 1) }} KB
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right text-sm {{ ($script['blocking_time'] ?? 0) > 250 ? 'font-medium text-red-600' : 'text-gray-700' }}">
                            {{ round($script['blocking_time'] ?? 0) }} ms
                        </td>
                        <td class="px-3 py-2 text-right text-sm text-gray-700">
                            {{ round($script['main_thread_time'] ?? 0) }} ms
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-200 bg-gray-50">
                    <td class="px-3 py-2 text-sm font-semibold text-gray-900">Total</td>
                    <td class="px-3 py-2 text-right text-sm font-semibold text-gray-900">
                        @if($totalSize >= 1048576)
                            {{ round($totalSize / 1048576, 1) }} MB
                        @else
                            {{ round($totalSize / 1024, 1) }} KB
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right text-sm font-semibold {{ $totalBlocking > 250 ? 'text-red-600' : 'text-gray-900' }}">
                        {{ round($totalBlocking) }} ms
                    </td>
                    <td class="px-3 py-2 text-right text-sm font-semibold text-gray-900">
                        {{ round($totalThread) }} ms
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-ui.card>
