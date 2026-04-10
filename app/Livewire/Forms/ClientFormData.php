<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Rules\RomanianCui;
use Livewire\Attributes\Validate;
use Livewire\Form;

class ClientFormData extends Form
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|email|max:255')]
    public string $email = '';

    #[Validate('nullable|string|max:50')]
    public string $phone = '';

    #[Validate('nullable|string|max:255')]
    public string $company = '';

    #[Validate('nullable|url|max:255')]
    public string $website = '';

    #[Validate('nullable|string|max:255')]
    public string $address = '';

    #[Validate('nullable|string|max:100')]
    public string $city = '';

    #[Validate('nullable|string|max:100')]
    public string $country = '';

    #[Validate('nullable|string|max:100')]
    public string $county = '';

    #[Validate('nullable|string|max:20')]
    public string $postal_code = '';

    public string $vat_number = '';

    public string $registration_number = '';

    public bool $vat_payer = false;

    #[Validate('nullable|string|max:255')]
    public string $company_status = '';

    #[Validate('nullable|string|max:5000')]
    public string $notes = '';

    #[Validate('required|in:active,inactive,archived')]
    public string $status = 'active';

    public function rules(): array
    {
        return [
            'vat_number' => ['nullable', 'string', 'max:50', new RomanianCui],
            'registration_number' => ['nullable', 'string', 'max:50', 'regex:/^J\d{1,2}\/\d+\/\d{4}$/'],
        ];
    }

    public function setFromClient($client): void
    {
        $this->name = $client->name ?? '';
        $this->email = $client->email ?? '';
        $this->phone = $client->phone ?? '';
        $this->company = $client->company ?? '';
        $this->website = $client->website ?? '';
        $this->address = $client->address ?? '';
        $this->city = $client->city ?? '';
        $this->country = $client->country ?? '';
        $this->county = $client->county ?? '';
        $this->postal_code = $client->postal_code ?? '';
        $this->vat_number = $client->vat_number ?? '';
        $this->registration_number = $client->registration_number ?? '';
        $this->vat_payer = $client->vat_payer ?? false;
        $this->company_status = $client->company_status ?? '';
        $this->notes = $client->notes ?? '';
        $this->status = $client->status ?? 'active';
    }
}
