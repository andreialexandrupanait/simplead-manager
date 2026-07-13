<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use Illuminate\Validation\Rule;
use Livewire\Form;

class SiteWizardFormData extends Form
{
    public string $url = '';

    public string $name = '';

    public ?int $clientId = null;

    public ?int $planId = null;

    // Inline client creation
    public string $newClientName = '';

    public string $newClientEmail = '';

    /**
     * Validation rules for the wizard.
     *
     * The `url` uniqueness check ignores soft-deleted sites so that a URL
     * whose only conflict is a previously-removed site can be re-added.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:255', $this->uniqueUrlRule()],
            'name' => ['required', 'string', 'max:255'],
            'clientId' => ['nullable', 'exists:clients,id'],
            'planId' => ['nullable', 'exists:maintenance_plans,id'],
        ];
    }

    /**
     * Validate only Step 1 fields (URL + name).
     */
    public function validateStep1(): void
    {
        $this->validateOnly('url', [
            'url' => ['required', 'url', 'max:255', $this->uniqueUrlRule()],
        ]);
        $this->validateOnly('name', [
            'name' => 'required|string|max:255',
        ]);
    }

    /**
     * Validate connectivity-check prerequisite.
     */
    public function validateUrl(): void
    {
        $this->validateOnly('url', [
            'url' => 'required|url',
        ]);
    }

    /**
     * Validate inline client creation fields.
     */
    public function validateNewClient(): void
    {
        $this->validate([
            'newClientName' => 'required|string|max:255',
            'newClientEmail' => 'nullable|email|max:255',
        ]);
    }

    /**
     * Unique-URL rule that excludes soft-deleted sites.
     */
    private function uniqueUrlRule(): \Illuminate\Validation\Rules\Unique
    {
        return Rule::unique('sites', 'url')->whereNull('deleted_at');
    }
}
