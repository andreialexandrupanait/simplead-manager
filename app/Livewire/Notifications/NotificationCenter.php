<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Models\InAppNotification;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class NotificationCenter extends Component
{
    use WithPagination;

    public string $filter = 'all';

    public string $search = '';

    public string $typeFilter = 'all';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return InAppNotification::forUser(auth()->id())
            ->unread()
            ->count();
    }

    #[Computed]
    public function totalCount(): int
    {
        return InAppNotification::forUser(auth()->id())->count();
    }

    public function markAsRead(int $id): void
    {
        InAppNotification::where('id', $id)
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        unset($this->unreadCount);
    }

    public function markAllAsRead(): void
    {
        InAppNotification::forUser(auth()->id())
            ->unread()
            ->update(['read_at' => now()]);

        unset($this->unreadCount);
        $this->dispatch('notifications-updated');
    }

    public function deleteOld(): void
    {
        InAppNotification::forUser(auth()->id())
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(30))
            ->delete();

        unset($this->totalCount, $this->unreadCount);
    }

    public function render(): \Illuminate\View\View
    {
        $notifications = InAppNotification::forUser(auth()->id())
            ->when($this->filter === 'unread', fn ($q) => $q->unread())
            ->when($this->filter === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when($this->typeFilter !== 'all', fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->search, function ($q) {
                $term = '%'.str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $this->search).'%';
                $q->where(function ($sq) use ($term) {
                    $sq->where('title', 'ilike', $term)
                        ->orWhere('message', 'ilike', $term);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('livewire.notifications.notification-center', [
            'notifications' => $notifications,
        ])->layout('components.layouts.app', ['title' => 'Notifications']);
    }
}
