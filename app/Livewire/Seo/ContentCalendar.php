<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\SeoContent;
use App\Models\Site;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ContentCalendar extends Component
{
    public int $year;

    public int $month;

    public ?int $siteFilter = null;

    public function mount(): void
    {
        $this->year = now()->year;
        $this->month = now()->month;
    }

    #[Computed]
    public function sites()
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function calendarDays(): array
    {
        $start = Carbon::create($this->year, $this->month, 1)->startOfWeek(Carbon::MONDAY);
        $end = Carbon::create($this->year, $this->month, 1)->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $days = [];
        $current = $start->copy();
        while ($current->lte($end)) {
            $days[] = $current->copy();
            $current->addDay();
        }

        return $days;
    }

    #[Computed]
    public function events(): array
    {
        $start = Carbon::create($this->year, $this->month, 1)->startOfWeek(Carbon::MONDAY);
        $end = Carbon::create($this->year, $this->month, 1)->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $query = SeoContent::with('site')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('scheduled_at', [$start, $end])
                    ->orWhereBetween('published_at', [$start, $end]);
            })
            ->when($this->siteFilter, fn ($q) => $q->where('site_id', $this->siteFilter));

        $items = $query->get();

        $grouped = [];
        foreach ($items as $item) {
            $date = ($item->scheduled_at ?? $item->published_at ?? $item->created_at)->format('Y-m-d');
            $grouped[$date][] = $item;
        }

        return $grouped;
    }

    public function previousMonth(): void
    {
        $date = Carbon::create($this->year, $this->month, 1)->subMonth();
        $this->year = $date->year;
        $this->month = $date->month;
        unset($this->calendarDays, $this->events);
    }

    public function nextMonth(): void
    {
        $date = Carbon::create($this->year, $this->month, 1)->addMonth();
        $this->year = $date->year;
        $this->month = $date->month;
        unset($this->calendarDays, $this->events);
    }

    public function reschedule(int $contentId, string $date): void
    {
        $content = SeoContent::findOrFail($contentId);
        $content->update(['scheduled_at' => Carbon::parse($date)]);
        unset($this->events);
    }

    public function updatedSiteFilter(): void
    {
        unset($this->events);
    }

    public function render()
    {
        return view('livewire.seo.content-calendar')
            ->layout('components.layouts.app', ['title' => 'Editorial Calendar']);
    }
}
