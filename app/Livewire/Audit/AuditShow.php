<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Enums\AuditRunStatus;
use App\Jobs\Audit\RunSfCrawl;
use App\Models\Audit;
use App\Models\AuditCheckResult;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Faza D: one audit's page — target + lifecycle status, the crawl trigger, live
 * run progress (polled while running), and the deterministic + AI result summary.
 * The validation editor is a follow-up wave.
 */
class AuditShow extends Component
{
    public Audit $audit;

    public function mount(Audit $audit): void
    {
        $this->audit = $audit;
    }

    #[Computed]
    public function latestRun(): ?\App\Models\AuditRun
    {
        return $this->audit->runs()->latest('id')->first();
    }

    #[Computed]
    public function isRunning(): bool
    {
        $status = $this->latestRun()?->status;

        return $status === AuditRunStatus::Running || $status === AuditRunStatus::Pending;
    }

    /**
     * @return array{total: int, exista: int, nu_exista: int, nu_se_aplica: int, manual: int}
     */
    #[Computed]
    public function resultCounts(): array
    {
        /** @var array<string, int> $rows */
        $rows = AuditCheckResult::query()
            ->where('audit_id', $this->audit->id)
            ->selectRaw('state, count(*) as c')
            ->groupBy('state')
            ->pluck('c', 'state')
            ->all();

        $exista = (int) ($rows['EXISTA'] ?? 0);
        $nuExista = (int) ($rows['NU_EXISTA'] ?? 0);
        $nuSeAplica = (int) ($rows['NU_SE_APLICA'] ?? 0);
        $total = array_sum(array_map('intval', $rows));

        return [
            'total' => $total,
            'exista' => $exista,
            'nu_exista' => $nuExista,
            'nu_se_aplica' => $nuSeAplica,
            'manual' => $total - $exista - $nuExista - $nuSeAplica,
        ];
    }

    public function startCrawl(): void
    {
        abort_if((bool) Auth::user()?->isViewer(), 403, 'Viewers cannot start a crawl.');

        if ($this->isRunning()) {
            $this->dispatch('notify', type: 'warning', message: 'Un crawl este deja în desfășurare pentru acest audit.');

            return;
        }

        RunSfCrawl::dispatch($this->audit->id);
        unset($this->latestRun, $this->isRunning);
        $this->dispatch('notify', type: 'success', message: 'Crawl pornit — colectarea rulează în fundal.');
    }

    public function render(): View
    {
        return view('livewire.audit.audit-show', [
            'run' => $this->latestRun(),
            'counts' => $this->resultCounts(),
        ])->layout('components.layouts.app', ['title' => __('Audit')]);
    }
}
