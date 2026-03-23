<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class SiteWizardFormData extends Form
{
    #[Validate('required|url|max:255|unique:sites,url')]
    public string $url = '';

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|exists:clients,id')]
    public ?int $clientId = null;

    #[Validate('nullable|exists:maintenance_plans,id')]
    public ?int $planId = null;

    // Inline client creation
    public string $newClientName = '';

    public string $newClientEmail = '';

    /**
     * Validate only Step 1 fields (URL + name).
     */
    public function validateStep1(): void
    {
        $this->validateOnly('url', [
            'url' => 'required|url|max:255|unique:sites,url',
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
}
