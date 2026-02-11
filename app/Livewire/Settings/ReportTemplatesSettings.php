<?php

namespace App\Livewire\Settings;

use App\Models\ReportTemplate;
use Livewire\Component;

class ReportTemplatesSettings extends Component
{
    public ?int $editingTemplateId = null;

    public string $name = '';
    public string $description = '';
    public array $sections = [];
    public string $company_name = '';
    public string $company_website = '';
    public string $primary_color = '#7C3AED';
    public string $intro_text = '';
    public string $closing_text = '';

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'sections' => 'required|array|min:1',
            'company_name' => 'nullable|string|max:255',
            'company_website' => 'nullable|string|max:255',
            'primary_color' => 'required|string|max:7',
            'intro_text' => 'nullable|string',
            'closing_text' => 'nullable|string',
        ];
    }

    public function openCreateForm(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal-template-form');
    }

    public function editTemplate(int $id): void
    {
        $template = ReportTemplate::findOrFail($id);
        $this->editingTemplateId = $template->id;
        $this->name = $template->name;
        $this->description = $template->description ?? '';
        $this->sections = $template->sections ?? [];
        $this->company_name = $template->company_name ?? '';
        $this->company_website = $template->company_website ?? '';
        $this->primary_color = $template->primary_color ?? '#7C3AED';
        $this->intro_text = $template->intro_text ?? '';
        $this->closing_text = $template->closing_text ?? '';
        $this->dispatch('open-modal-template-form');
    }

    public function saveTemplate(): void
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'sections' => $this->sections,
            'company_name' => $this->company_name ?: null,
            'company_website' => $this->company_website ?: null,
            'primary_color' => $this->primary_color,
            'intro_text' => $this->intro_text ?: null,
            'closing_text' => $this->closing_text ?: null,
        ];

        if ($this->editingTemplateId) {
            ReportTemplate::findOrFail($this->editingTemplateId)->update($data);
            session()->flash('template-success', 'Template updated.');
        } else {
            ReportTemplate::create($data);
            session()->flash('template-success', 'Template created.');
        }

        $this->dispatch('close-modal-template-form');
        $this->resetForm();
    }

    public function duplicateTemplate(int $id): void
    {
        $template = ReportTemplate::findOrFail($id);
        $new = $template->replicate();
        $new->name = $template->name . ' (Copy)';
        $new->is_default = false;
        $new->save();
        session()->flash('template-success', 'Template duplicated.');
    }

    public function deleteTemplate(int $id): void
    {
        $template = ReportTemplate::findOrFail($id);

        if ($template->schedules()->exists()) {
            session()->flash('template-error', 'Cannot delete — this template is used by active schedules.');
            return;
        }

        $template->delete();
        session()->flash('template-success', 'Template deleted.');
    }

    public function setDefault(int $id): void
    {
        ReportTemplate::where('is_default', true)->update(['is_default' => false]);
        ReportTemplate::findOrFail($id)->update(['is_default' => true]);
        session()->flash('template-success', 'Default template updated.');
    }

    public function resetForm(): void
    {
        $this->editingTemplateId = null;
        $this->name = '';
        $this->description = '';
        $this->sections = ['overview', 'updates', 'uptime', 'backups', 'analytics', 'search_console', 'performance', 'links'];
        $this->company_name = '';
        $this->company_website = '';
        $this->primary_color = '#7C3AED';
        $this->intro_text = '';
        $this->closing_text = '';
    }

    public function cancelForm(): void
    {
        $this->dispatch('close-modal-template-form');
        $this->resetForm();
    }

    public function render()
    {
        $templates = ReportTemplate::withCount('schedules')->orderBy('name')->get();

        return view('livewire.settings.report-templates-settings', [
            'templates' => $templates,
        ])->layout('components.layouts.app', ['title' => 'Report Templates']);
    }
}
