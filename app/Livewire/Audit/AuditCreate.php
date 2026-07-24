<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Enums\AuditStatus;
use App\Enums\ProspectProfile;
use App\Exceptions\SsrfException;
use App\Models\Audit;
use App\Models\Prospect;
use App\Models\Site;
use App\Services\Security\SsrfGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Faza D: create an audit against a managed site XOR a prospect (new or existing).
 * The DB CHECK enforces exactly one target; the URL is the crawl entry point.
 */
class AuditCreate extends Component
{
    /** 'site' | 'prospect' */
    public string $targetType = 'prospect';

    public ?int $siteId = null;

    /** 'existing' | 'new' */
    public string $prospectMode = 'new';

    public ?int $prospectId = null;

    // New-prospect fields.
    public string $name = '';

    public string $prospectUrl = '';

    public string $profile = ProspectProfile::B2bServicii->value;

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contextNotes = '';

    public function mount(): void
    {
        abort_if((bool) Auth::user()?->isViewer(), 403, 'Viewers cannot create audits.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        if ($this->targetType === 'site') {
            return ['siteId' => 'required|integer|exists:sites,id'];
        }
        if ($this->prospectMode === 'existing') {
            return ['prospectId' => 'required|integer|exists:prospects,id'];
        }

        return [
            'name' => 'required|string|max:255',
            'prospectUrl' => 'required|url|max:2048',
            'profile' => 'required|string|in:'.implode(',', array_map(fn (ProspectProfile $p) => $p->value, ProspectProfile::cases())),
            'contactName' => 'nullable|string|max:255',
            'contactEmail' => 'nullable|email|max:255',
        ];
    }

    public function save()
    {
        abort_if((bool) Auth::user()?->isViewer(), 403, 'Viewers cannot create audits.');
        $this->validate();

        [$attributes, $url] = $this->resolveTarget();

        // Prospect URLs are fully user-supplied and get crawled server-side —
        // reject internal / loopback / metadata targets. Managed sites are trusted.
        if ($this->targetType === 'prospect') {
            try {
                app(SsrfGuard::class)->assertPublicUrl($url);
            } catch (SsrfException) {
                $this->addError('prospectUrl', 'Acest URL nu poate fi auditat — indică o adresă privată sau internă.');

                return null;
            }
        }

        $audit = Audit::create([
            ...$attributes,
            'status' => AuditStatus::Configurat,
            'url' => $url,
            'context_notes' => $this->contextNotes !== '' ? $this->contextNotes : null,
            'methodology_version' => '2.0',
            'created_by' => Auth::id(),
        ]);

        session()->flash('status', 'Audit creat — pornește colectarea când ești gata.');

        return $this->redirectRoute('audits.show', ['audit' => $audit->id], navigate: true);
    }

    /**
     * @return array{0: array{site_id?: int, prospect_id?: int}, 1: string}
     */
    private function resolveTarget(): array
    {
        if ($this->targetType === 'site') {
            $site = Site::findOrFail($this->siteId);

            return [['site_id' => $site->id], $site->url];
        }

        if ($this->prospectMode === 'existing') {
            $prospect = Prospect::findOrFail($this->prospectId);

            return [['prospect_id' => $prospect->id], $prospect->url];
        }

        $prospect = Prospect::create([
            'name' => $this->name,
            'url' => $this->prospectUrl,
            'profile' => ProspectProfile::from($this->profile),
            'contact_name' => $this->contactName !== '' ? $this->contactName : null,
            'contact_email' => $this->contactEmail !== '' ? $this->contactEmail : null,
        ]);

        return [['prospect_id' => $prospect->id], $prospect->url];
    }

    public function render(): View
    {
        return view('livewire.audit.audit-create', [
            'sites' => Site::query()->orderBy('name')->get(['id', 'name', 'url']),
            'prospects' => Prospect::query()->orderBy('name')->get(['id', 'name', 'url']),
            'profiles' => ProspectProfile::cases(),
        ])->layout('components.layouts.app', ['title' => __('New audit')]);
    }
}
