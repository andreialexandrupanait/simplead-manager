<div class="flex items-center gap-px" title="Last 24 hours uptime">
    @foreach($this->segments as $segment)
        <div class="h-6 flex-1 rounded-sm {{ $segment === null ? 'bg-gray-200' : ($segment ? 'bg-green-500' : 'bg-red-500') }}"></div>
    @endforeach
</div>
