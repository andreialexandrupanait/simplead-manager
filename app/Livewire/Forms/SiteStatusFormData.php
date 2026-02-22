<?php

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class SiteStatusFormData extends Form
{
    #[Validate('required|string|max:255')]
    public string $statusName = '';

    #[Validate('required|string|max:7')]
    public string $statusColor = '#6b7280';

    #[Validate('required|integer|min:0')]
    public int $statusSortOrder = 0;
}
